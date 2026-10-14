<?php
/**
 * Load the AI scan classes.
 */
foreach ([
    'AiPaths.php',
    'AiStore.php',
    'AiConfig.php',
    'AiExcludeList.php',
    'AiPolicy.php',
    'AiSecretPaths.php',
    'AiSecretMask.php',
    'AiDatabaseQueue.php',
    'AiReadLog.php',
    'AiVerdictStore.php',
    'AiPrompt.php',
    'AiReplyParser.php',
    'AiLatch.php',
    'AiFileTime.php',
    'AiHostLogs.php',
    'AiExcerpt.php',
    'AiQueue.php',
    'AiUsage.php',
    'AiChatClient.php',
    'AiReview.php',
    'AiFollowUp.php',
    'AiSpread.php',
    'AiReport.php',
    'AiChecksumSource.php',
    'AiPipeline.php',
] as $file) {
    require_once __DIR__ . '/' . $file;
}
