<?php
// Get auth token
$ch = curl_init("http://localhost/accounting/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]));
$res = curl_exec($ch);
$json = json_decode($res, true);
curl_close($ch);

$token = $json['token'] ?? '';
if (!$token) {
    die("Failed to login to API. Response: $res\n");
}
echo "Got token for UI test.\n";

$harness = '<!DOCTYPE html>
<html>
<head><title>Save Invoice UI Test</title></head>
<body>
<div id="status">Starting test...</div>
<pre id="logs" style="white-space: pre-wrap; font-family: monospace;"></pre>
<iframe id="app-frame" src="http://localhost/accounting/" style="width:1280px; height:900px;"></iframe>

<script>
localStorage.setItem("accounting_token", ' . json_encode($token) . ');

const logs = [];
function log(msg) {
  logs.push(msg);
  document.getElementById("logs").textContent = logs.join("\n");
}

const frame = document.getElementById("app-frame");

frame.onload = async () => {
  log("Iframe loaded.");
  try {
    const fWin = frame.contentWindow;
    const fDoc = frame.contentDocument;

    fWin.addEventListener("error", (e) => {
      log("UNCAUGHT ERROR: " + e.message);
    });

    // Wait for React to mount
    await new Promise(r => setTimeout(r, 2500));

    // Click Invoicing & Billing tab
    const buttons = Array.from(fDoc.querySelectorAll("button"));
    const invoicingBtn = buttons.find(b => b.textContent.includes("Invoicing & Billing") || b.textContent.includes("Invoicing"));
    if (!invoicingBtn) {
      log("ERROR: Invoicing tab not found");
      return;
    }
    invoicingBtn.click();
    log("Clicked Invoicing tab.");
    await new Promise(r => setTimeout(r, 1500));

    // Fill Customer Name
    const inputs = Array.from(fDoc.querySelectorAll("input"));
    const custInput = inputs.find(i => i.placeholder && i.placeholder.includes("Zenith"));
    if (custInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(custInput, "Global Tech Corp");
      custInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set Customer Name: Global Tech Corp");
    } else {
      log("Warning: Customer input field not found directly by placeholder.");
    }

    // Fill item description
    const descInput = inputs.find(i => i.placeholder && i.placeholder.includes("Software Development"));
    if (descInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(descInput, "Full Stack Cloud Platform");
      descInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set item description: Full Stack Cloud Platform");
    }

    // Fill unit price
    const priceInput = inputs.find(i => i.placeholder === "0.00");
    if (priceInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(priceInput, "50000");
      priceInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set unit price: 50000");
    }

    await new Promise(r => setTimeout(r, 500));

    // Find submit button
    const submitBtn = Array.from(fDoc.querySelectorAll("button")).find(b => 
      b.textContent.includes("Save & Generate Professional Invoice") || 
      b.textContent.includes("Save & Generate Invoice") ||
      b.textContent.includes("Generate Invoice")
    );

    if (!submitBtn) {
      log("ERROR: Submit button not found!");
      return;
    }

    log("Clicking Save & Generate Invoice button...");
    submitBtn.click();

    // Wait for API call and re-render
    await new Promise(r => setTimeout(r, 3500));

    // Check for fatal error
    const fatalOverlay = fDoc.getElementById("fatal-error-overlay");
    if (fatalOverlay) {
      log("FAILED: Fatal error overlay displayed:\n" + fatalOverlay.innerText);
    } else {
      log("SUCCESS: No fatal error overlay!");
    }

    // Check for success banner
    const pageText = fDoc.body.innerText;
    if (pageText.includes("Invoice Generated Successfully!") || pageText.includes("Share on WhatsApp")) {
      log("SUCCESS: Success banner is rendered with WhatsApp Share and Print options!");
    } else {
      log("Notice: Current page snippet: " + pageText.slice(0, 400));
    }

    // Check WhatsApp link
    const waLink = fDoc.querySelector("a[href*=\"wa.me\"]");
    if (waLink) {
      log("SUCCESS: WhatsApp link present: " + waLink.href.slice(0, 80) + "...");
    }

  } catch (err) {
    log("TEST SCRIPT ERROR: " + err.message + "\n" + err.stack);
  }
};
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/save_test_runner.html', $harness);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=15000 --dump-dom "http://localhost/accounting/tests/save_test_runner.html"';
$output = shell_exec($chrome);

if (preg_match('/<pre id="logs"[^>]*>([\s\S]*?)<\/pre>/', $output, $matches)) {
    echo "\n=== BROWSER TEST LOGS ===\n" . html_entity_decode($matches[1]) . "\n";
} else {
    echo "Could not extract logs from DOM output.\n";
    echo substr($output, 0, 1000);
}
