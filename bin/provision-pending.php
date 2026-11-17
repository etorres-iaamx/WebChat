<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$limit = isset($argv[1]) ? (int) $argv[1] : 10;
$processed = webchat_auth()->processPendingProvisioningJobs($limit);
printf("Processed %d provisioning job(s).\n", $processed);
