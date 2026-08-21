<?php
/**
 * Clean Sweep - CleanSweep_Worker Registry
 *
 * Maps a work-unit type string to a CleanSweep_Worker implementation.
 * The orchestrator looks up the worker for each claimed unit; it does
 * not know which concrete classes exist.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_WorkerRegistry {

    /** @var array<string,CleanSweep_Worker> type => worker */
    private array $workers = [];

    /**
     * @param CleanSweep_Worker[] $workers
     */
    public function __construct(array $workers = []) {
        foreach ($workers as $w) {
            $this->register($w);
        }
    }

    public function register(CleanSweep_Worker $worker): void {
        $this->workers[$worker->type()] = $worker;
    }

    public function get(string $type): ?CleanSweep_Worker {
        return $this->workers[$type] ?? null;
    }

    public function has(string $type): bool {
        return isset($this->workers[$type]);
    }

    /**
     * All registered work-unit types.
     * @return string[]
     */
    public function types(): array {
        return array_keys($this->workers);
    }
}
