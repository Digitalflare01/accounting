<?php
$html = file_get_contents(__DIR__ . '/../public/index.html');

$checks = [
    'DocumentIngestionView Component' => strpos($html, 'function DocumentIngestionView') !== false,
    'Document Ingestion Nav Button'   => strpos($html, "setActiveTab('documents')") !== false,
    'Document Ingestion Tab Router'   => strpos($html, "activeTab === 'documents'") !== false,
    'File Drag & Drop Area (onDragOver)' => strpos($html, 'onDragOver') !== false && strpos($html, 'handleFileUpload') !== false,
    'Sample Documents Preset'         => strpos($html, 'handleSampleLoad') !== false,
    'Batch Post Dispatch'             => strpos($html, 'handleBatchPost') !== false,
    'Quick Account Modal'             => strpos($html, 'showNewAccModal') !== false,
    'Review Table & Auto-Match'       => strpos($html, 'confidence_score') !== false,
];

echo "FRONTEND WIRING VERIFICATION:\n";
$allPass = true;
foreach ($checks as $name => $pass) {
    echo ($pass ? "  [PASS] " : "  [FAIL] ") . $name . "\n";
    if (!$pass) $allPass = false;
}

if ($allPass) {
    echo "SUCCESS: All frontend document ingestion components are correctly wired up!\n";
    exit(0);
} else {
    echo "FAILED: Some components are missing.\n";
    exit(1);
}
