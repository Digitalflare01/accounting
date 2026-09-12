<?php
$files = [
    'frontend/src/App.jsx' => file_get_contents(__DIR__ . '/../frontend/src/App.jsx'),
    'frontend/src/main.jsx' => file_get_contents(__DIR__ . '/../frontend/src/main.jsx'),
    'frontend/src/components/TransactionEntry.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/TransactionEntry.jsx'),
    'frontend/src/components/AIDepreciationAdvisor.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/AIDepreciationAdvisor.jsx'),
    'frontend/src/components/ReportsView.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/ReportsView.jsx'),
    'frontend/src/components/LedgerView.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/LedgerView.jsx'),
    'frontend/src/components/BusinessProfileModal.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/BusinessProfileModal.jsx'),
    'frontend/src/components/InvoicingView.jsx' => file_get_contents(__DIR__ . '/../frontend/src/components/InvoicingView.jsx'),
];

$pubHtml = file_get_contents(__DIR__ . '/../public/index.html');
if (preg_match('/<script type="text\/babel">([\s\S]*?)<\/script>/', $pubHtml, $m)) {
    $files['public/index.html (babel script)'] = $m[1];
}

$harness = file_get_contents(__DIR__ . '/babel_test_harness.html');
$jsonFiles = json_encode($files);
$runnerHtml = str_replace(
    'window.done = true;',
    'window.done = true;',
    $harness
);
$runnerHtml = str_replace(
    '</script>' . "\n" . '</body>',
    'testFiles(' . $jsonFiles . ');' . "\n" . '</script>' . "\n" . '</body>',
    $runnerHtml
);

file_put_contents(__DIR__ . '/run_harness_temp.html', $runnerHtml);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=10000 --dump-dom "http://localhost/accounting/tests/run_harness_temp.html"';
$out = shell_exec($chrome);

if (preg_match('/<div id="results">([\s\S]*?)<\/div>/', $out, $matches)) {
    echo "BABEL COMPILATION RESULTS:\n";
    echo html_entity_decode($matches[1]) . "\n";
} else {
    echo "FAILED to get results from Chrome. Output snippet:\n" . substr($out, 0, 1000) . "\n";
}
