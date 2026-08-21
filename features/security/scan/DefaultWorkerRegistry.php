<?php
/**
 * Clean Sweep - Default CleanSweep_Worker Registry (factory)
 *
 * Builds the standard registry used by CleanSweep_Scanner.
 *
 * @since CleanSweep_Scanner v2
 */
require_once __DIR__ . '/workers/RootConfigWorker.php';
require_once __DIR__ . '/workers/CoreChecksumWorker.php';
require_once __DIR__ . '/workers/PackageChecksumWorker.php';
require_once __DIR__ . '/workers/FileDiscoveryWorker.php';
require_once __DIR__ . '/workers/FileBatchWorker.php';
require_once __DIR__ . '/workers/DbSegmentWorker.php';
require_once __DIR__ . '/workers/DbSiteDiscoveryWorker.php';
require_once __DIR__ . '/workers/IntegrityWorker.php';
require_once __DIR__ . '/workers/CensusWorker.php';
require_once __DIR__ . '/workers/FinalizeWorker.php';

final class CleanSweep_DefaultWorkerRegistry {

    public static function build(): CleanSweep_WorkerRegistry {
        return new CleanSweep_WorkerRegistry([
            new CleanSweep_RootConfigWorker(),
            new CleanSweep_CoreChecksumWorker(),
            new CleanSweep_PackageChecksumWorker(),
            new CleanSweep_FileDiscoveryWorker(),
            new CleanSweep_FileBatchWorker(),
            new CleanSweep_DbSegmentWorker(),
            new CleanSweep_DbSiteDiscoveryWorker(),
            new CleanSweep_IntegrityWorker(),
            new CleanSweep_CensusWorker(),
            new CleanSweep_FinalizeWorker(),
        ]);
    }
}
