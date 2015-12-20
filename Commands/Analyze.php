<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Common;
use Piwik\Exception\InvalidRequestParameterException;
use Piwik\Plugin;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Analyze extends ConsoleCommand
{
    private $mappingLettersToNumeric = array(
        '0' => 0,
        '1' => 1,
        '2' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
        '6' => 6,
        '7' => 7,
        '8' => 8,
        '9' => 9,
        'a' => 10,
        'b' => 11,
        'c' => 12,
        'd' => 13,
        'e' => 14,
        'f' => 15,
    );

    private $numQueuesAvailable = 1;

    private $startingLetter = array();

    protected function configure()
    {
        $this->setName('queuedtracking:analyze');
        $this->setAliases(array('queuedtracking:analyse'));
        $this->setDescription('Analyzes the requests that are currently in the queues. Executing this command may take a while');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $systemCheck = new SystemCheck();
        $systemCheck->checkRedisIsInstalled();

        $backend = Queue\Factory::makeBackend();
        $backend->testConnection();
        $redis   = $backend->getConnection();
        $manager = Queue\Factory::makeQueueManager($backend);

        $startTimer = microtime(true);

        $stats = array(
            'forcedUserId' => 0,
            'forcedVisitorId' => 0,
            'visitorId' => 0,
            'invalidRequests' => 0,
            'useVisitorIdForSharding' => 0,
            'useIPForSharding' => 0,
            'requestSetsWithMultipleRequests' => 0,
            'requestSetsWithOneRequest' => 0
        );

        $this->numQueuesAvailable = $manager->getNumberOfAvailableQueues();
        $oldDistribution = array_fill_keys(range(0, $this->numQueuesAvailable - 1), 0);
        $newDistribution = array_fill_keys(range(0, $this->numQueuesAvailable - 1), 0);

        foreach ($manager->getAllQueues() as $index => $queue) {
            $end = $queue->getNumberOfRequestSetsInQueue();
            $currentQueueId = $queue->getId();

            for ($start = 0; $start < $end; $start += 25) {
                $key = 'trackingQueueV1';
                if (!empty($currentQueueId)) {
                    $key .= '_' . $currentQueueId;
                }
                $values = $redis->lRange($key, $start, $start + 25);

                if (empty($values)) {
                    break;
                }

                foreach ($values as $value) {
                    $params = json_decode($value, true);

                    $requestSet = new Tracker\RequestSet();
                    $requestSet->restoreState($params);

                    if ($requestSet->getNumberOfRequests() <= 1) {
                        $stats['requestSetsWithOneRequest']++;
                    } else {
                        $stats['requestSetsWithMultipleRequests']++;
                    }

                    foreach ($requestSet->getRequests() as $request) {
                        if ($request->getForcedUserId()) {
                            $stats['forcedUserId']++;
                        } else if ($request->getForcedVisitorId()) {
                            $stats['forcedVisitorId']++;
                        } else if (Common::getRequestVar('_id', '', 'string', $request->getParams())) {
                            $stats['visitorId']++;
                        }

                        try {
                            $visitorId = $request->getVisitorId();
                        } catch (InvalidRequestParameterException $e) {
                            $stats['invalidRequests']++;
                            $visitorId = null;
                        }

                        if (empty($visitorId)) {
                            $stats['useIPForSharding']++;
                            $newQueueId = $this->getQueueIdForVisitor(md5($request->getIpString()));
                        } else {
                            $stats['useVisitorIdForSharding']++;
                            $newQueueId = $this->getQueueIdForVisitor(bin2hex($visitorId));
                        }

                        $oldDistribution[$currentQueueId]++;
                        $newDistribution[$newQueueId]++;
                    }
                }

                $statsReadable = $this->toReadableArray($stats);

                $message = sprintf('Currently analyzing queue %d of %d (%d of about %d request sets). Stats: %s, OldDistribution: %s, NewDistribution: %s        ', $queue->getId() + 1, $this->numQueuesAvailable, $start + 25, $end, implode(', ', $statsReadable), implode(' + ', $oldDistribution), implode(' + ', $newDistribution));
                $output->writeln($message);
            }
        }

        $diff = (microtime(true) - $startTimer);

        $output->writeln('');

        ksort($this->startingLetter, SORT_NATURAL);
        $readableLetters = $this->toReadableArray($this->startingLetter);
        $output->writeln(sprintf('Starting letter analysis: %s', implode(', ', $readableLetters)));
        $output->writeln(sprintf('Analysed %d requests within %d request sets in %d second(s)', array_sum($oldDistribution), $stats['requestSetsWithOneRequest'] + $stats['requestSetsWithMultipleRequests'], ceil($diff)));
    }

    private function toReadableArray($arrayKeyValue)
    {
        $arrayReadable = array();
        foreach ($arrayKeyValue as $key => $value) {
            $arrayReadable[] = "$key: $value";
        }

        return $arrayReadable;
    }

    protected function getQueueIdForVisitor($visitorId)
    {
        $visitorId = strtolower(substr($visitorId, 0, 1));

        if (!isset($this->startingLetter[$visitorId])) {
            $this->startingLetter[$visitorId] = 1;
        } else {
            $this->startingLetter[$visitorId]++;
        }

        if (isset($this->mappingLettersToNumeric[$visitorId])) {
            $id = $this->mappingLettersToNumeric[$visitorId];
        } else {
            $id = ord($visitorId);
        }

        return $id % $this->numQueuesAvailable;
    }

}
