<?php
require_once __DIR__ . '/../api/config/Database.php';
require_once __DIR__ . '/../api/middleware/Auth.php';

use App\Middleware\Auth;

$token = Auth::generateToken([
    'sub' => 13,
    'email' => 'info@nextgenkerala.in',
    'role' => 'user',
    'status' => 'active',
    'tier' => 'pro'
]);

$harness = '<!DOCTYPE html>
<html>
<head><title>Save and Print Banner Test</title></head>
<body>
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
  try {
    const fWin = frame.contentWindow;
    const fDoc = frame.contentDocument;

    fWin.addEventListener("error", (e) => {
      log("UNCAUGHT RUNTIME ERROR: " + e.message);
    });

    await new Promise(r => setTimeout(r, 2500));

    // Click Invoicing tab
    const buttons = Array.from(fDoc.querySelectorAll("button"));
    const invoicingBtn = buttons.find(b => b.textContent.includes("Invoicing & Billing") || b.textContent.includes("Invoicing"));
    invoicingBtn.click();
    log("Clicked Invoicing tab.");
    await new Promise(r => setTimeout(r, 1500));

    // Fill customer
    const inputs = Array.from(fDoc.querySelectorAll("input"));
    const custInput = inputs.find(i => i.placeholder && i.placeholder.includes("Zenith"));
    if (custInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(custInput, "Print Test Client");
      custInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set Customer: Print Test Client");
    }

    // Fill description
    const descInput = inputs.find(i => i.placeholder && i.placeholder.includes("Software Development"));
    if (descInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(descInput, "IT Services");
      descInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set description: IT Services");
    }

    // Fill rate
    const priceInput = inputs.find(i => i.placeholder === "0.00");
    if (priceInput) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(fWin.HTMLInputElement.prototype, "value").set;
      nativeInputValueSetter.call(priceInput, "12000");
      priceInput.dispatchEvent(new fWin.Event("input", { bubbles: true }));
      log("Set rate: 12000");
    }

    await new Promise(r => setTimeout(r, 500));

    // Click Save & Generate Invoice
    const submitBtn = Array.from(fDoc.querySelectorAll("button")).find(b => 
      b.textContent.includes("Save & Generate") || b.textContent.includes("Generate Invoice")
    );
    submitBtn.click();
    log("Clicked Save & Generate Invoice.");
    await new Promise(r => setTimeout(r, 3500));

    let fatal = fDoc.getElementById("fatal-error-overlay");
    if (fatal) {
      log("FAILED on save: " + fatal.innerText);
      return;
    }
    log("SUCCESS: Invoice generated without error.");

    // Now click Print Professional Bill button from the success banner
    const bannerPrintBtn = Array.from(fDoc.querySelectorAll("button")).find(b => 
      b.textContent.includes("Print Professional Bill")
    );

    if (bannerPrintBtn) {
      log("Clicking Print Professional Bill button from success banner...");
      bannerPrintBtn.click();
      await new Promise(r => setTimeout(r, 2500));

      fatal = fDoc.getElementById("fatal-error-overlay");
      if (fatal) {
        log("FAILED on print: " + fatal.innerText);
      } else {
        log("SUCCESS: No fatal error on clicking Print Professional Bill!");
      }

      const printArea = fDoc.querySelector(".print-area");
      if (printArea) {
        log("SUCCESS: Professional Print View modal is rendered!");
      }
    } else {
      log("Could not find Print Professional Bill button on banner.");
    }

  } catch (err) {
    log("TEST SCRIPT ERROR: " + err.message);
  }
};
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/banner_print_test.html', $harness);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=15000 --dump-dom "http://localhost/accounting/tests/banner_print_test.html"';
$output = shell_exec($chrome);

if (preg_match('/<pre id="logs"[^>]*>([\s\S]*?)<\/pre>/', $output, $matches)) {
    echo "\n=== BROWSER BANNER PRINT TEST LOGS ===\n" . html_entity_decode($matches[1]) . "\n";
} else {
    echo "Could not extract logs.\n" . substr($output, 0, 1000);
}

@unlink(__DIR__ . '/banner_print_test.html');
