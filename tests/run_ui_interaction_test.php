<?php
// Get a valid login token first
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
echo "Got token: " . substr($token, 0, 20) . "...\n";

// Now build an HTML file that sets token in localStorage and loads public/index.html in an iframe,
// or tests public/index.html directly.
$runner = '<!DOCTYPE html>
<html>
<head><title>E2E UI Test</title></head>
<body>
<div id="status">Testing...</div>
<pre id="logs" style="white-space: pre-wrap; font-family: monospace;"></pre>
<iframe id="app-frame" src="http://localhost/accounting/public/index.html" style="width:1200px; height:800px;"></iframe>

<script>
localStorage.setItem("accounting_token", ' . json_encode($token) . ');

const logs = [];
function log(msg) {
  logs.push(msg);
  document.getElementById("logs").textContent = logs.join("\n");
}

window.addEventListener("message", (e) => {
  log("Message: " + JSON.stringify(e.data));
});

const frame = document.getElementById("app-frame");

frame.onload = async () => {
  log("Iframe loaded.");
  try {
    const fWin = frame.contentWindow;
    const fDoc = frame.contentDocument;

    // Hook into iframe errors
    fWin.addEventListener("error", (e) => {
      log("IFRAME RUNTIME ERROR: " + e.message + " at " + e.filename + ":" + e.lineno);
    });

    // Wait for React to mount
    await new Promise(r => setTimeout(r, 2000));

    const fatalOverlay = fDoc.getElementById("fatal-error-overlay");
    if (fatalOverlay) {
      log("FATAL OVERLAY DETECTED ON MOUNT:\n" + fatalOverlay.innerText);
    } else {
      log("No fatal overlay on initial mount.");
    }

    // Find tab buttons
    const buttons = Array.from(fDoc.querySelectorAll("button"));
    log("Found " + buttons.length + " buttons.");

    // Test 1: Click Profile & Logo button
    const profileBtn = buttons.find(b => b.textContent.includes("Profile & Logo") || b.textContent.includes("Profile"));
    if (profileBtn) {
      log("Clicking Profile & Logo button...");
      profileBtn.click();
      await new Promise(r => setTimeout(r, 1000));
      const profileModal = fDoc.querySelector("h2");
      log("Profile modal heading: " + (profileModal ? profileModal.textContent : "Not found"));
      
      // Click sub-tabs in profile modal
      const modalButtons = Array.from(fDoc.querySelectorAll("button"));
      for (const tabLabel of ["Address & Contact", "Bank & UPI Details", "Invoice Terms & Signatory", "Identity & Logo"]) {
        const tBtn = modalButtons.find(b => b.textContent.includes(tabLabel));
        if (tBtn) {
          tBtn.click();
          await new Promise(r => setTimeout(r, 400));
          log("Switched profile tab to: " + tabLabel);
        }
      }
      
      // Close profile modal
      const closeBtn = modalButtons.find(b => b.textContent.trim() === "✕" || b.textContent.trim() === "Close");
      if (closeBtn) {
        closeBtn.click();
        await new Promise(r => setTimeout(r, 500));
        log("Closed profile modal.");
      }
    } else {
      log("Could not find Profile & Logo button.");
    }

    // Test 2: Click Invoicing & Billing tab
    const updatedButtons = Array.from(fDoc.querySelectorAll("button"));
    const invoicingBtn = updatedButtons.find(b => b.textContent.includes("Invoicing & Billing") || b.textContent.includes("Invoicing"));
    if (invoicingBtn) {
      log("Clicking Invoicing & Billing tab...");
      invoicingBtn.click();
      await new Promise(r => setTimeout(r, 1500));
      
      const fatalAfterInvoicing = fDoc.getElementById("fatal-error-overlay");
      if (fatalAfterInvoicing) {
        log("FATAL OVERLAY AFTER CLICKING INVOICING:\n" + fatalAfterInvoicing.innerText);
      } else {
        log("Invoicing & Billing tab loaded cleanly.");
        
        // Verify Ledger Checkbox exists
        const ledgerCheckbox = fDoc.getElementById("postToLedgerCheck");
        log("Double-Entry Ledger Checkbox found: " + Boolean(ledgerCheckbox) + ", checked: " + (ledgerCheckbox ? ledgerCheckbox.checked : false));
        
        // Verify line item inputs exist
        const textInputs = Array.from(fDoc.querySelectorAll("input"));
        log("Found " + textInputs.length + " input fields on Invoicing screen.");
        
        // Check Invoices List view
        const historyBtn = Array.from(fDoc.querySelectorAll("button")).find(b => b.textContent.includes("Invoices List"));
        if (historyBtn) {
          historyBtn.click();
          await new Promise(r => setTimeout(r, 1000));
          log("Switched to Invoices List archive.");
        }
      }
    } else {
      log("Could not find Invoicing button.");
    }

    // Look for tabs: AI Ingestion, Financial Reports, General Ledger
    const reportsBtn = Array.from(fDoc.querySelectorAll("button")).find(b => b.textContent.includes("Financial Reports") || b.textContent.includes("Reports"));
    if (reportsBtn) {
      log("Clicking Reports tab...");
      reportsBtn.click();
      await new Promise(r => setTimeout(r, 1500));

      const fatalAfterReports = fDoc.getElementById("fatal-error-overlay");
      if (fatalAfterReports) {
        log("FATAL OVERLAY AFTER CLICKING REPORTS:\n" + fatalAfterReports.innerText);
      } else {
        log("Reports tab loaded cleanly.");
        // Try clicking sub-tabs: Balance Sheet, Trial Balance, Cash Flow, GST, Principles
        const subButtons = Array.from(fDoc.querySelectorAll("button"));
        for (const label of ["Balance Sheet", "Trial Balance", "Cash Flow", "GST Compliance", "Knowledge Hub"]) {
          const btn = subButtons.find(b => b.textContent.includes(label));
          if (btn) {
            log("Clicking sub-tab: " + label);
            btn.click();
            await new Promise(r => setTimeout(r, 1000));
            const subFatal = fDoc.getElementById("fatal-error-overlay");
            if (subFatal) {
              log("FATAL ERROR ON " + label + ":\n" + subFatal.innerText);
            }
          }
        }
      }
    } else {
      log("Could not find Reports button. Page text snippet: " + fDoc.body.innerText.slice(0, 300));
    }

    // Click Ledger tab
    const ledgerBtn = Array.from(fDoc.querySelectorAll("button")).find(b => b.textContent.includes("General Ledger") || b.textContent.includes("Ledger"));
    if (ledgerBtn) {
      log("Clicking General Ledger tab...");
      ledgerBtn.click();
      await new Promise(r => setTimeout(r, 1500));
      const fatalAfterLedger = fDoc.getElementById("fatal-error-overlay");
      if (fatalAfterLedger) {
        log("FATAL OVERLAY AFTER CLICKING LEDGER:\n" + fatalAfterLedger.innerText);
      } else {
        log("Ledger tab loaded cleanly.");
      }
    }

    log("TEST COMPLETE.");
    document.getElementById("status").textContent = "DONE";
  } catch (err) {
    log("TEST ERROR: " + err.message + "\n" + err.stack);
    document.getElementById("status").textContent = "ERROR";
  }
};
</script>
</body>
</html>';

file_put_contents(__DIR__ . '/test_ui_interactions.html', $runner);

$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=20000 --dump-dom "http://localhost/accounting/tests/test_ui_interactions.html"';
$out = shell_exec($chrome);
file_put_contents(__DIR__ . '/interaction_results.html', $out);

if (preg_match('/<pre id="logs"[^>]*>([\s\S]*?)<\/pre>/', $out, $m)) {
    echo "INTERACTION LOGS:\n" . html_entity_decode($m[1]) . "\n";
} else {
    echo "No logs found in output.\n";
}
