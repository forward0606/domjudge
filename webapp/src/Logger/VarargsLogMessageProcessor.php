<?php declare(strict_types=1);

/*
 * A simply message processor for Monolog, that uses printf style argument
 * passing.
 */

namespace App\Logger;

use ValueError;
use Monolog\Processor\ProcessorInterface;

class VarargsLogMessageProcessor implements ProcessorInterface
{
    /**
     * @param  array $record
     * @return array
     */
    public function __invoke(array $record): array
    {
        if (!str_contains($record['message'], '%') || empty($record['context'])) {
            return $record;
        }

        $res = false;
        try {
            $res = vsprintf($record['message'], $record['context']);
        } catch (ValueError) {}

        if ($res !== false) {
            $record['message'] = $res;
            $record['context'] = [];
        }

        return $record;
    }
}
