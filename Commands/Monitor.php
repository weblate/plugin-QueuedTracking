<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\SystemCheck;

class Monitor extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:monitor');
        $this->setDescription("Shows and updates the current state of the queue every 2 seconds.\n  Key ,=first page, .=last page, 0-9=move to page section, arrow LEFT=prev page, RIGHT=next page, UP=next 10 pages, DOWN=prev 10 pages, q=quit");
        $this->addRequiredValueOption('iterations', null, 'If set, will limit the number of monitoring iterations done.');
        $this->addRequiredValueOption('perpage', 'p', 'Number of queue worker displayed per page.', 16);
    }

    /**
     * @return int
     */
    protected function doExecute(): int
    {
        $output = $this->getOutput();
        $settings = Queue\Factory::getSettings();

        if ($settings->isRedisBackend()) {
            $systemCheck = new SystemCheck();
            $systemCheck->checkRedisIsInstalled();
        }

        if (!$this->isPcntlFunctionAvailable()) {
            $output->write(str_repeat("\r\n", 100));
            $output->write("\e[".(100)."A");
        }

        $iterations = $this->getIterationsFromArg();
        if ($iterations  !== null) {
            $output->writeln("<info>Only running " . $iterations . " iterations.</info>");
        }

        if ($settings->queueEnabled->getValue()) {
            $output->writeln('Queue is enabled');
        } else {
            $output->writeln('<comment>' . strtoupper('Queue is disabled: ') . 'No new requests will be written into the queue, processing the remaining requests is still possible.</comment>');
        }

        $backend = Queue\Factory::makeBackend();
        $manager = Queue\Factory::makeQueueManager($backend);
        $queues  = $manager->getAllQueues();
        $lock    = Queue\Factory::makeLock($backend);

        if ($settings->processDuringTrackingRequest->getValue()) {
            $output->writeln('Request sets in the queue will be processed automatically after a tracking request');
        } else {
            $output->writeln('The command <comment>./console queuedtracking:process</comment> has to be executed to process request sets within queue');
        }

        $output->writeln(sprintf('Up to <info>%d</> workers will be used', $manager->getNumberOfAvailableQueues()));
        $output->writeln(sprintf('Processor will start once there are at least <info>%s</> request sets in the queue',
                                 $manager->getNumberOfRequestsToProcessAtSameTime()));
        $iterationCount = 0;
        
        $qCurrentPage   = 1;
        $qCount         = count($queues);
        $qPerPAge       = min(max($this->getPerPageFromArg(), 1), $qCount);
        $qPageCount     = ceil($qCount / $qPerPAge);

        $signalTrap = function() use ($output) {
            $output->writeln("\e[u\e[?25h");
            die;
        };
        if ($this->isPcntlFunctionAvailable())
        {
            pcntl_signal(SIGINT, $signalTrap);
            pcntl_signal(SIGTERM, $signalTrap);    
        }
        
        readline_callback_handler_install('', function() {});
        stream_set_blocking (STDIN, false);

        $output->writeln(str_repeat("-", 30));
        $output->writeln("<fg=black;bg=white;options=bold>".str_pad(" Q INDEX", 10).str_pad(" | REQUEST SETS", 20)."</>");
        $output->writeln(str_repeat("-", 30));
        $output->write("\e[?25l");
        
        $lastStatsTimer = microtime(true) - 2;
        $lastSumInQueue = false;
        $diffSumInQueue = 0;
        $keyPressed     = "";
        while (1) {
            if ($this->isPcntlFunctionAvailable()) {
                pcntl_signal_dispatch();
            }   

            if (microtime(true) - $lastStatsTimer >= 2 || $keyPressed != "")
            {
                $qCurrentPage = min(max($qCurrentPage, 1), $qPageCount);
                $memory = $backend->getMemoryStats(); // I know this will only work with redis currently as it is not defined in backend interface etc. needs to be refactored once we add another backend
                
                $sumInQueue = 0;
                foreach ($queues as $sumQ) {
                    $sumInQueue += $sumQ->getNumberOfRequestSetsInQueue();
                }

                if ($lastSumInQueue !== false) {
                    $diffSumInQueue = $lastSumInQueue - $sumInQueue;
                    $diffRps        = round($diffSumInQueue / (microtime(true) - $lastStatsTimer), 2);
                    $diffSumInQueue = $diffSumInQueue < 0 ? "<fg=red;options=bold>".abs($diffRps)."</>" : "<fg=green;options=bold>{$diffRps}</>";
                }
    
                $numInQueue = 0;
                for ($idxPage = 0; $idxPage < $qPerPAge; $idxPage++) {
                    $idx = ($qCurrentPage - 1) * $qPerPAge + $idxPage;
                    if (isset($queues[$idx])) {
                        $q = $queues[$idx]->getNumberOfRequestSetsInQueue();
                        $numInQueue += (int)$q;
                        $output->writeln(str_pad($idx, 10, " ", STR_PAD_LEFT)." | ".str_pad(number_format($q), 16, " ", STR_PAD_LEFT));
                    } else {
                        $output->writeln(str_pad("", 10)." | ".str_pad("", 16));
                    }
                }
    
                $output->writeln(str_repeat("-", 30));
                $output->writeln("<fg=black;bg=white;options=bold>".str_pad(" ".($qCount)." Q", 10)." | ".str_pad(number_format($sumInQueue)." R", 16)."</>");
                $output->writeln(str_repeat("-", 30));
                $output->writeln(sprintf(
                    "Q [%s-%s] | <info>page %s/%s</> | <comment>press (0-9.,q) or arrow(L,R,U,D)</> | diff/sec %s         \n".
                    "%s used memory (%s peak). <info>%d</> workers active.".str_repeat(" ", 15), 
                    ($idx - $qPerPAge + 1), 
                    $idx, $qCurrentPage, $qPageCount, $diffSumInQueue,
                    $memory['used_memory_human'] ?? 'Unknown', 
                    $memory['used_memory_peak_human'] ?? 'Unknown', 
                    $lock->getNumberOfAcquiredLocks()
                ));
                $output->write("\e[s");
                $output->write("\e[0G");
                $output->write("\e[".($qPerPAge + 5)."A");
        
                if (!is_null($iterations)) {
                    $iterationCount += 1;
                    if ($iterationCount >= $iterations) {
                        break;
                    }
                }

                $lastSumInQueue = $sumInQueue;
                $lastStatsTimer = microtime(true);
            }

            $keyStroke  = stream_get_contents(STDIN, 3);
            $keyPressed = strlen($keyStroke) == 3 ? $keyStroke[2] : (strlen($keyStroke) > 0 ? $keyStroke[0] : "");
            if ($keyPressed != "" and in_array($keyPressed, array(".", ",", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "A", "B", "C", "D", "q"))) {
                switch ($keyPressed) {
                    case "0": case "1": case "2": case "3": case "4": 
                    case "5": case "6": case "7": case "8": case "9":
                        $keyPressed = $keyPressed != "0" ? $keyPressed : "10";
                        $qCurrentPage = floor(($qCurrentPage - 0.1) / 10) * 10 + (int)$keyPressed; break;
                    case "C": 
                        $qCurrentPage++;
                        break;
                    case "D": 
                        $qCurrentPage--;
                        break;
                    case "A": 
                        $qCurrentPage += 10;
                        break;
                    case "B": 
                        $qCurrentPage -= 10;
                        break;
                    case ",": 
                        $qCurrentPage = 1;
                        break;
                    case ".": 
                        $qCurrentPage = $qPageCount;
                        break;
                    case "q":
                        $signalTrap();
                }
            }

            usleep(5000);
        }

        return self::SUCCESS;
    }

    /**
     * Loads the `iteration` argument from the commands arguments. `null` indicates no limit supplied.
     *
     * @return int|null
     */
    private function getIterationsFromArg()
    {
        $iterations = $this->getInput()->getOption('iterations');
        if (empty($iterations) && $iterations !== 0 && $iterations !== '0') {
            $iterations = null;
        } elseif (!is_numeric($iterations)) {
            throw new \Exception('iterations needs to be numeric');
        } else {
            $iterations = (int)$iterations;
            if ($iterations <= 0) {
                throw new \Exception('iterations needs to be a non-zero positive number');
            }
        }
        return $iterations;
    }

    /**
     * Loads the `perpage` argument from the commands arguments.
     *
     * @return int|null
     */    
    private function getPerPageFromArg()
    {
        $perPage = $this->getInput()->getOption('perpage');
        if (!is_numeric($perPage)) {
            throw new \Exception('perpage needs to be numeric');
        } else {
            $perPage = (int)$perPage;
            if ($perPage <= 0) {
                throw new \Exception('perpage needs to be a non-zero positive number');
            }
        }
        return $perPage;
    }

    private function isPcntlFunctionAvailable()
    {
        if (extension_loaded('pcntl') && function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch')) {
            return true;
        }

        return false;
    }
}
