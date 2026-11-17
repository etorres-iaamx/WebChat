<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/auth.js');
if ($source === false) {
    throw new RuntimeException('Unable to read public/auth.js');
}

$capture = strpos($source, 'const verificationPayload = Object.fromEntries(new FormData(forms.verify).entries());');
$lock = strpos($source, 'setFormBusy(forms.verify, true);');
$verifySubmit = strpos($source, "submit(forms.verify, 'verify', verificationPayload)");
$retrySubmit = strpos($source, 'provisionRuntimeWithRetry(verificationPayload)');

if ($capture === false || $lock === false || $capture > $lock) {
    throw new RuntimeException('Verification payload must be captured before the form is disabled.');
}
if ($verifySubmit === false || $retrySubmit === false) {
    throw new RuntimeException('Verification and retry requests must reuse the captured payload.');
}

echo "PASS (auth verification payload regression)\n";
