# ApexLedger - Intelligent AI Accounting & Financial Management System

ApexLedger is a modern, high-performance financial accounting software engineered with double-entry accounting principles, native GST tax compliance, multi-format financial document ingestion, AI-assisted transaction analysis, and multi-tier subscription quota enforcement.

---

## 🚀 Key Features

### 1. 📁 Multi-Format Financial Document Ingestion & Posting
- **Multi-Format Extraction**: Upload bank statements, invoices, supplier bills, receipts, or spreadsheets in `.pdf`, `.docx`, `.doc`, `.txt`, `.csv`, `.tsv`, `.xlsx`, or image formats.
- **Pure-PHP Document Parser**: Decompresses PDF FlateDecode streams (`gzuncompress`), parses Word XML tables (`ZipArchive`), reads Excel sheets, and uses heuristic column mappers for CSV/TSV without requiring external OS binaries.
- **Intelligent Account Mapping**: Automatically scans narrations and classifies them against active Chart of Accounts (Software/Cloud, Rent, Hardware/Assets, Salaries, Utilities, Sales/Revenue) with confidence scoring.
- **Interactive Disambiguation Grid**: Review, edit dates/descriptions/amounts, adjust GST rates (0%, 5%, 12%, 18%, 28%) or interstate supply, and post balanced double entries in bulk or individually.

### 2. 🤖 AI Natural Language Bookkeeping & Disambiguation
- Single-prompt transaction entry: Type natural statements like *"Paid ₹15,000 for AWS cloud servers from HDFC Bank"* to automatically generate balanced debits and credits.
- Instant fallback & circuit breaker protection ensuring near-instant local heuristic processing even when remote AI endpoints are unreachable.

### 3. ⚖️ Comprehensive Financial Statements & Reports
- **Trial Balance**: Live checking of debit and credit equilibrium with instant difference calculation.
- **Profit & Loss (P&L)**: Revenue and Expense breakdowns with Gross Profit and Net Profit metrics.
- **Balance Sheet**: Assets, Liabilities, and Owner's Equity balanced to perfection.
- **Cash Flow Statement**: Direct method tracking operating, investing, and financing cash flows.
- **General Ledger & Journal**: Complete transaction history with audit trail and `entry_group_id` links.

### 4. 🧾 Professional GST Invoicing & Billing
- Create GST-compliant invoices with automatic HSN/SAC code handling, CGST/SGST vs. IGST calculation, and round-off adjustments.
- Instant print/PDF previews with business logo, bank account details for NEFT/RTGS, and UPI QR codes for instant payments.
- WhatsApp sharing integration and client ledger synchronization.

### 5. 💳 Multi-Tier Subscriptions & Calibrated Free Plan
- 4 comprehensive subscription tiers:
  - **Free Starter (₹0)**: Calibrated with 15 transactions, 5 invoices, and 10 AI queries to experience the software with full feature access.
  - **Starter Solo (₹499/mo)**: 300 transactions, 100 invoices, 150 AI queries.
  - **Professional Growth (₹1,499/mo)**: 3,000 transactions, 1,000 invoices, 1,500 AI queries, depreciation advisor.
  - **Enterprise Elite (₹3,999/mo)**: Unlimited volume and dedicated multi-GST support.
- Coupon code system (e.g. `WELCOME50` for 50% discount).
- Automatic upgrade prompts upon reaching tier thresholds.

### 6. 🛡️ Enterprise Admin Portal & Passcode Verification
- Dedicated admin portal at `/admin/index.html`.
- Secure admin registration with secret passcode protection (`Parayulla@NGK`).
- Telemetry, user management, plan overrides, and system health monitoring.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.1+ (Object-Oriented, MVC architecture, custom routing, JWT authentication, PDO database layer).
- **Frontend**: Single-Page Application (SPA) with React 18, Babel standalone, and Tailwind CSS.
- **Databases**: 
  - Dual-mode connection manager: Automatically connects to **MySQL / MariaDB** (WAMP/XAMPP/Production), with seamless automatic fallback to portable **SQLite** (`database/accounting.sqlite`).
- **AI & Document Parser**: Native PHP compression streams (`ZipArchive`, `gzuncompress`), regex heuristic classifiers, and Google Gemini API integration.

---

## 📦 Getting Started

### Prerequisites
- PHP 8.1 or higher (with `pdo`, `pdo_mysql`, `pdo_sqlite`, `zip` extensions enabled).
- Apache or Nginx (or local WAMP/XAMPP server).
- (Optional) MySQL 8.0+ / MariaDB.

### Installation
1. Clone the repository:
   ```bash
   git clone https://github.com/Digitalflare01/accounting.git
   cd accounting
   ```

2. Configure environment variables:
   ```bash
   cp .env.example .env
   ```
   Add your Google Gemini API key in `.env` if you wish to use multimodal AI parsing.

3. Serve the application:
   - If using **WAMP/XAMPP**, place the folder in `www/accounting` or `htdocs/accounting`.
   - Or start PHP's built-in server:
     ```bash
     php -S localhost:8000
     ```

4. Access the software:
   - **Public Client**: `http://localhost/accounting/public/index.html` (or `http://localhost:8000/public/index.html`)
   - **Admin Portal**: `http://localhost/accounting/admin/index.html` (or `http://localhost:8000/admin/index.html`)
   - **Default Admin Login**: `admin@accounting.local` / `password123`
   - **Admin Registration Passcode**: `Parayulla@NGK`

---

## 🧪 Testing

ApexLedger includes comprehensive automated test suites:
```bash
# Run all tests
php tests/test_backend.php
php tests/test_admin.php
php tests/test_document_ingestion.php
php tests/test_subscriptions_and_limits.php
```

---

## 📄 License
MIT License.
