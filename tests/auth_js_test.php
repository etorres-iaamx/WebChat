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
$falseProvisioningError = strpos($source, 'No fue posible completar la operación. Intenta nuevamente más tarde.');
$emailVerifiedSuccess = strpos($source, 'Correo verificado y registro completado');

if ($capture === false || $lock === false || $capture > $lock) {
    throw new RuntimeException('Verification payload must be captured before the form is disabled.');
}
if ($verifySubmit === false) {
    throw new RuntimeException('The verification request must reuse the captured payload.');
}
if ($retrySubmit !== false) {
    throw new RuntimeException('Provisioning retries must not convert an already-completed email verification into a user-facing error.');
}
if ($falseProvisioningError !== false) {
    throw new RuntimeException('Provisioning failures must not use the generic operation-failure state after verification.');
}
if ($emailVerifiedSuccess === false) {
    throw new RuntimeException('Pending provisioning must preserve a completed-registration success state.');
}

echo "PASS (auth verification payload regression)\n";
