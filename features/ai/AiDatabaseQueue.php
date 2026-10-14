<?php
/**
 * The only method that may create database AI units.
 * This build returns an empty list. A later build fills collect() when mode is send.
 */
final class AiDatabaseQueue {

    /**
     * @param array<int,array> $threats
     * @return array<int,array>
     */
    public function collect(AiPolicy $policy, array $threats): array {
        unset($policy, $threats);
        return [];
    }
}
