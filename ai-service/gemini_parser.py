"""
AI Parser Module for Hybrid Accounting & Tax Preparation Application
Leverages Google Gemini API to parse natural language financial inputs into
structured accounting data with strict schema validation and human-in-the-loop
hallucination/ambiguity detection.
"""

import os
import json
import re
from typing import List, Optional, Dict, Any
from pydantic import BaseModel, Field

# ============================================================================
# EXACT GEMINI SYSTEM PROMPT
# ============================================================================
GEMINI_ACCOUNTING_SYSTEM_PROMPT = """
You are a Senior Forensic Accountant and Indian Tax Compliance AI Specialist.
Your job is to parse raw natural language financial descriptions into deterministic,
structured accounting ledger JSON data.

### STRICT INSTRUCTIONS:
1. You must output ONLY a valid, raw JSON object. No Markdown code fences (e.g. do NOT use ```json), no explanations, no prefix, no postfix.
2. The JSON object MUST strictly adhere to this exact schema:
{
  "parsed_amount": <number: float or integer>,
  "transaction_type": <string: "credit" or "debit">,
  "suggested_category": <string: general category like "commission", "consulting", "software_development", "electronics", "rent", "utilities", "salary">,
  "suggested_account_name": <string: specific target Chart of Accounts name, e.g. "Commission Income", "Software Development Services", "Office Rent", "Consulting Income", "Salaries & Wages Expense", "Fixed Asset - Computers">,
  "needs_user_review": <boolean: true or false>,
  "review_options": <array of strings: candidate Chart of Accounts names for user disambiguation>,
  "gst_extracted": <number: numeric GST amount if mentioned or calculated, else 0>,
  "confidence_score": <number: float between 0.0 and 1.0>
}

### FINANCIAL CONVENTION & RULES:
- "parsed_amount":
  - Understand Indian colloquial terms:
    - 1 lakh / lac = 100000
    - 1 crore / cr = 10000000
    - 50k / 50 thousand = 50000
  - Extract the pure numeric value without currency symbols.
- "transaction_type":
  - "debit": for money going out, purchases (e.g. "i buy office desktop", "buy printer", "purchased chair", "bought laptop"), expenses, asset purchases, supplier payments, bills.
  - "credit": for money coming in, sales revenue, consulting fees, commission received, capital investments, refunds.
- "gst_extracted":
  - If a GST rate or amount is explicitly mentioned (e.g., "with 18% GST" on 50000 -> 9000, or "GST 1800"), extract/calculate it. If not explicitly specified, set to 0.
- "needs_user_review" & "review_options" (HALLUCINATION & AMBIGUITY CHECK):
  - In accounting, certain capital purchases vs operational expenditures are ambiguous and MUST NEVER be decided unilaterally by AI.
  - If the purchase could be either a Capital Expenditure (Fixed Asset - capitalized on Balance Sheet) or a Revenue Expenditure (Office Expense - expensed on P&L), you MUST set "needs_user_review": true and provide the exact options in "review_options".
  - Examples of Ambiguity:
    - Desktops / Laptops / Macbooks / Computers / Monitors / Servers / Printers -> ["Fixed Asset - Computers", "Office Expense"]
    - Office Furniture / AC / Chairs / Desks -> ["Office Equipment & Furniture", "Office Expense"]
    - Software licenses / Enterprise ERP setup -> ["Software Subscriptions & Cloud", "Fixed Asset - Software License"]
    - Vehicles / Transport machinery -> ["Fixed Asset - Vehicles", "Travel & Conveyance"]
  - If the transaction is unambiguous (e.g., "Paid office rent 25000" -> "Office Rent", "Received consulting fees 50000" -> "Consulting Income"), set "needs_user_review": false and "review_options": [].
- "confidence_score":
  - Set between 0.70 and 0.85 when ambiguous ("needs_user_review": true).
  - Set between 0.90 and 0.99 when straightforward and unambiguous.
"""

# ============================================================================
# PYDANTIC RESPONSE SCHEMA
# ============================================================================
class ParsedTransactionResponse(BaseModel):
    parsed_amount: float = Field(..., description="Parsed total transaction amount")
    transaction_type: str = Field(..., description="'credit' or 'debit'")
    suggested_category: str = Field(..., description="Category tag (e.g. electronics, rent)")
    needs_user_review: bool = Field(..., description="Flag indicating accounting ambiguity requiring human review")
    review_options: List[str] = Field(default_factory=list, description="Array of candidate Chart of Accounts")
    gst_extracted: float = Field(default=0.0, description="Extracted or computed GST amount")
    confidence_score: float = Field(..., description="Confidence score between 0.0 and 1.0")
    suggested_account_name: Optional[str] = Field(default=None, description="Recommended Chart of Accounts target account")
    target_account: Optional[Dict[str, Any]] = Field(default=None, description="Target account object with name and type")


class GeminiFinancialParser:
    def __init__(self, api_key: Optional[str] = None):
        self.api_key = api_key or os.getenv("GEMINI_API_KEY", "")
        self.client = None
        self._init_gemini_client()

    def _init_gemini_client(self):
        """Initializes the official google-genai SDK client if key is present."""
        if not self.api_key:
            return
        try:
            from google import genai
            self.client = genai.Client(api_key=self.api_key)
        except Exception as e:
            # Fallback or log if SDK is not installed or import error
            self.client = None

    def parse_text(self, text: str) -> Dict[str, Any]:
        """
        Parses raw natural language text using Gemini API with fallback to
        deterministic heuristic accounting parser.
        """
        cleaned_text = text.strip()
        if not cleaned_text:
            return {
                "parsed_amount": 0.0,
                "transaction_type": "debit",
                "suggested_category": "general",
                "needs_user_review": False,
                "review_options": [],
                "gst_extracted": 0.0,
                "confidence_score": 0.0
            }

        # 1. Attempt Gemini API Call if client initialized
        if self.client:
            try:
                from google.genai import types
                response = self.client.models.generate_content(
                    model="gemini-2.5-flash",
                    contents=cleaned_text,
                    config=types.GenerateContentConfig(
                        system_instruction=GEMINI_ACCOUNTING_SYSTEM_PROMPT,
                        response_mime_type="application/json",
                        temperature=0.1
                    )
                )
                raw_json = response.text.strip()
                # Clean any accidental fences if present
                raw_json = re.sub(r"^```(?:json)?", "", raw_json, flags=re.IGNORECASE)
                raw_json = re.sub(r"```$", "", raw_json).strip()
                data = json.loads(raw_json)
                
                # Validate against Pydantic schema
                validated = ParsedTransactionResponse(**data)
                return validated.model_dump()
            except Exception as ex:
                # Log error and fall through to deterministic fallback
                pass

        # 2. Resilient Deterministic Fallback Engine
        return self._heuristic_fallback_parser(cleaned_text)

    def _heuristic_fallback_parser(self, text: str) -> Dict[str, Any]:
        """
        High-precision rule-based parser that guarantees exact schema compliance
        and mirrors the Gemini System Prompt logic for offline execution and tests.
        """
        lower = text.lower()

        count_nouns = r'(?:(?:[a-z-]+\s+){0,2}(?:clients?|customers?|people|persons?|items?|units?|months?|pcs?|pieces?|seats?|workstations?|desks?|chairs?|licenses?|licences?|packages?|users?|subscribers?|invoices?|bills?))'
        
        # 1. Multiplier Detection (e.g. "3 clients they paid 60000 each")
        amount = 0.0
        has_multiplier = False
        qty = 1.0
        unit_amount = 0.0

        m1 = re.search(r'(\d+)\s*' + count_nouns + r'\b.*?(?:paid|pay|for|at|of|billed|received)?\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?\s*each', lower)
        m2 = re.search(r'(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?\s*each\b.*?(?:from|for|by|to|with)?\s*(\d+)\s*' + count_nouns + '?', lower)
        m3 = re.search(r'(\d+)\s*' + count_nouns + r'\s*(?:each\s*paid|each\s*paying|each\s*for)\s*(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d+)?)\s*(lakh|lacs|lac|cr|crore|k)?', lower)

        def _apply_suffix(val: float, s: str) -> float:
            s = (s or '').lower()
            if s in ['lakh', 'lacs', 'lac']: return val * 100000.0
            if s in ['crore', 'cr']: return val * 10000000.0
            if s == 'k': return val * 1000.0
            return val

        if m1:
            qty = float(m1.group(1))
            unit = float(m1.group(2).replace(',', ''))
            unit_amount = _apply_suffix(unit, m1.group(3))
            amount = qty * unit_amount
            has_multiplier = True
        elif m2:
            unit = float(m2.group(1).replace(',', ''))
            unit_amount = _apply_suffix(unit, m2.group(2))
            qty = float(m2.group(3)) if m2.group(3) else 1.0
            amount = qty * unit_amount
            has_multiplier = True
        elif m3:
            qty = float(m3.group(1))
            unit = float(m3.group(2).replace(',', ''))
            unit_amount = _apply_suffix(unit, m3.group(3))
            amount = qty * unit_amount
            has_multiplier = True
        else:
            # Match "1 lakh", "2.5 lacs", "1.5 crore", "50k", "50,000"
            lakh_match = re.search(r'([\d\.]+)\s*(?:lakh|lacs|lac)', lower)
            crore_match = re.search(r'([\d\.]+)\s*(?:crore|cr)', lower)
            k_match = re.search(r'([\d\.]+)\s*k\b', lower)

            if lakh_match:
                amount = float(lakh_match.group(1)) * 100000.0
            elif crore_match:
                amount = float(crore_match.group(1)) * 10000000.0
            elif k_match:
                amount = float(k_match.group(1)) * 1000.0
            else:
                # Find all numbers and avoid count nouns if large numbers exist
                matches = list(re.finditer(r'(?:rs\.?|inr|₹|\$)?\s*([\d,]+(?:\.\d{1,2})?)', lower))
                candidates = []
                for mt in matches:
                    val_str = mt.group(1).replace(',', '')
                    try:
                        val = float(val_str)
                        after = lower[mt.end():mt.end() + 30]
                        is_count = bool(re.match(r'^\s*' + count_nouns + r'\b', after))
                        candidates.append((val, is_count))
                    except ValueError:
                        pass
                
                non_counts = [c[0] for c in candidates if not c[1] and c[0] > 0]
                if non_counts:
                    amount = max(non_counts)
                elif candidates:
                    amount = max(c[0] for c in candidates)
                else:
                    amount = 0.0

        # Determine Transaction Type (credit vs debit)
        credit_keywords = [
            'got income', 'income from', 'received income', 'clients paid', 'client paid',
            'they paid', 'customer paid', 'customers paid', 'paid me', 'paid us',
            'received', 'billed', 'invoiced', 'invoice sent', 'sale', 'sales',
            'consulting fee', 'revenue', 'income', 'infused', 'capital infusion',
            'collection from'
        ]
        debit_keywords = [
            'i paid', 'we paid', 'paid rent', 'paid bill', 'paid salary',
            'paid for', 'bought', 'purchased', 'spent', 'expense', 'paid to'
        ]
        has_credit = any(kw in lower for kw in credit_keywords)
        has_debit = any(kw in lower for kw in debit_keywords)
        if has_credit:
            transaction_type = "credit"
        elif has_debit:
            transaction_type = "debit"
        else:
            transaction_type = "debit" if ('paid' in lower or 'spent' in lower) else "credit"

        # Ambiguity / Target Account Auto-Selection
        suggested_category = "general"
        suggested_account_name = ""
        needs_user_review = False
        review_options: List[str] = []
        confidence_score = 0.95

        if transaction_type == "credit":
            # Commission / Brokerage / Outside Work Transfer / Referral
            if any(term in lower for term in ['commission', 'commition', 'commision', 'comision', 'brokerage', 'brokrage', 'referral', 'incentive', 'work transfer', 'transfer to outside', 'outside work', 'subcontract', 'agency fee', 'finder fee']):
                suggested_category = "commission"
                suggested_account_name = "Commission Income"
                review_options = ["Commission Income", "Consulting Income", "Software Development Services"]
                confidence_score = 0.98
            # Interest / Fixed Deposits / Investments
            elif any(term in lower for term in ['interest', 'bank interest', 'fd interest', 'fixed deposit', 'dividend']):
                suggested_category = "interest"
                suggested_account_name = "Interest & Investment Income"
                review_options = ["Interest & Investment Income", "Consulting Income"]
                confidence_score = 0.98
            # Web, E-Commerce, Software Development
            elif any(term in lower for term in ['website', 'web site', 'ecommerce', 'e commerce', 'e-commerce', 'web app', 'portal', 'software', 'coding', 'development', 'programming']):
                suggested_category = "software_development"
                suggested_account_name = "Software Development Services"
                review_options = ["Software Development Services", "Consulting Income"]
                confidence_score = 0.98
            elif any(term in lower for term in ['consulting', 'advisory', 'client fee', 'training', 'retainer']):
                suggested_category = "services"
                suggested_account_name = "Consulting Income"
                review_options = ["Consulting Income", "Software Development Services"]
                confidence_score = 0.96
            elif any(term in lower for term in ['hardware', 'goods', 'product', 'sale', 'sold', 'retail', 'wholesale']):
                suggested_category = "sales"
                suggested_account_name = "Sales of Hardware & Goods"
                review_options = ["Sales of Hardware & Goods", "Consulting Income"]
                confidence_score = 0.95
            else:
                suggested_category = "revenue"
                suggested_account_name = "Consulting Income"
                review_options = ["Consulting Income", "Software Development Services", "Commission Income"]
                confidence_score = 0.88
        else:
            # Commission / Brokerage Expense
            if any(term in lower for term in ['commission', 'commition', 'brokerage', 'brokrage', 'referral fee', 'agent fee']):
                suggested_category = "commission_expense"
                suggested_account_name = "Commission & Brokerage Expense"
                review_options = ["Commission & Brokerage Expense", "Office Expense"]
                confidence_score = 0.98
            # Salaries / Wages
            elif any(term in lower for term in ['salary', 'salaries', 'salery', 'wages', 'payroll', 'stipend', 'staff pay']):
                suggested_category = "salary"
                suggested_account_name = "Salaries & Wages Expense"
                review_options = ["Salaries & Wages Expense", "Office Expense"]
                confidence_score = 0.98
            # Advertising & Marketing
            elif any(term in lower for term in ['advertising', 'advertisement', 'marketing', 'facebook ads', 'google ads', 'meta ads', 'promotion']):
                suggested_category = "marketing"
                suggested_account_name = "Advertising & Marketing"
                review_options = ["Advertising & Marketing", "Office Expense"]
                confidence_score = 0.98
            # Bank Charges
            elif any(term in lower for term in ['bank charge', 'bank fee', 'processing fee', 'gateway charge', 'transaction fee']):
                suggested_category = "bank_charges"
                suggested_account_name = "Bank Charges & Processing Fees"
                review_options = ["Bank Charges & Processing Fees", "Office Expense"]
                confidence_score = 0.98
            # Rule A: Hardware / Computers / Macbooks / Electronics
            if any(term in lower for term in ['macbook', 'laptop', 'computer', 'pc', 'monitor', 'server', 'ipad', 'electronics']):
                suggested_category = "electronics"
                suggested_account_name = "Fixed Asset - Computers"
                needs_user_review = True
                review_options = ["Fixed Asset - Computers", "Office Expense"]
                confidence_score = 0.75

            # Rule B: Furniture / Fixtures
            elif any(term in lower for term in ['chair', 'desk', 'table', 'furniture', 'air conditioner', 'ac unit']):
                suggested_category = "furniture"
                suggested_account_name = "Office Equipment & Furniture"
                needs_user_review = True
                review_options = ["Office Equipment & Furniture", "Office Expense"]
                confidence_score = 0.78

            # Rule C: Rent
            elif 'rent' in lower:
                suggested_category = "rent"
                suggested_account_name = "Office Rent"
                needs_user_review = False
                review_options = ["Office Rent"]
                confidence_score = 0.98

            # Rule D: Software / Subscriptions
            elif any(term in lower for term in ['aws', 'cloud', 'hosting', 'github', 'slack', 'software', 'saas', 'subscription']):
                suggested_category = "software"
                if amount >= 100000:
                    suggested_account_name = "Software Subscriptions & Cloud"
                    needs_user_review = True
                    review_options = ["Software Subscriptions & Cloud", "Fixed Asset - Computers"]
                    confidence_score = 0.80
                else:
                    suggested_account_name = "Software Subscriptions & Cloud"
                    needs_user_review = False
                    review_options = ["Software Subscriptions & Cloud"]
                    confidence_score = 0.94

            # Rule E: Utilities / Network / Recharge / Telecom
            elif any(term in lower for term in ['network', 'recharg', 'recharge', 'broadband', 'wifi', 'internet', 'phone', 'mobile', 'telecom', 'electric', 'electricity', 'power', 'water', 'utility', 'utilities', 'bill']):
                suggested_category = "utilities"
                suggested_account_name = "Utility Bills"
                needs_user_review = False
                review_options = ["Utility Bills", "Office Expense"]
                confidence_score = 0.95

            # Rule F: Travel & Conveyance
            elif any(term in lower for term in ['travel', 'conveyance', 'flight', 'hotel', 'taxi', 'cab', 'uber', 'ola', 'fuel', 'petrol', 'diesel']):
                suggested_category = "travel"
                suggested_account_name = "Travel & Conveyance"
                needs_user_review = False
                review_options = ["Travel & Conveyance", "Office Expense"]
                confidence_score = 0.95

            # Rule G: Legal & Professional
            elif any(term in lower for term in ['legal', 'advocate', 'lawyer', 'ca', 'auditor', 'audit', 'compliance', 'professional']):
                suggested_category = "professional_fees"
                suggested_account_name = "Legal & Professional Fees"
                needs_user_review = False
                review_options = ["Legal & Professional Fees", "Office Expense"]
                confidence_score = 0.96

            # Rule I: Petty expenses / snacks / stationery
            elif any(term in lower for term in ['coffee', 'tea', 'snacks', 'stationery', 'lunch', 'dinner', 'supplies']):
                suggested_category = "office_supplies"
                suggested_account_name = "Office Expense"
                needs_user_review = False
                review_options = ["Office Expense"]
                confidence_score = 0.95

            # Rule J: General Expense Keywords
            else:
                suggested_category = "office_expense"
                suggested_account_name = "Office Expense"
                needs_user_review = False
                review_options = ["Office Expense", "Utility Bills"]
                confidence_score = 0.90

        # Guarantee review_options has suggested_account_name first
        if suggested_account_name:
            if suggested_account_name not in review_options:
                review_options.insert(0, suggested_account_name)
            else:
                review_options.remove(suggested_account_name)
                review_options.insert(0, suggested_account_name)

        # GST Extraction
        gst_extracted = 0.0
        gst_percent_match = re.search(r'(\d+)\s*%\s*gst', lower)
        if gst_percent_match and amount > 0:
            rate = float(gst_percent_match.group(1))
            gst_extracted = round((amount * rate) / 100.0, 2)
        else:
            gst_amount_match = re.search(r'gst\s*(?:of|is|amount)?\s*(?:rs\.?|₹)?\s*([\d,]+)', lower)
            if gst_amount_match:
                gst_extracted = float(gst_amount_match.group(1).replace(',', ''))

        target_acc_type = "revenue" if transaction_type == "credit" else (
            "asset" if suggested_category in ["electronics", "furniture"] else "expense"
        )

        return {
            "parsed_amount": float(amount),
            "transaction_type": transaction_type,
            "suggested_category": suggested_category,
            "suggested_account_name": suggested_account_name,
            "target_account": {
                "name": suggested_account_name,
                "type": target_acc_type
            },
            "needs_user_review": needs_user_review,
            "review_options": review_options,
            "gst_extracted": float(gst_extracted),
            "confidence_score": confidence_score
        }

    def calculate_depreciation(self, prompt: str) -> Dict[str, Any]:
        """
        Calculates statutory depreciation for Indian accounting & tax compliance
        (Income Tax Act Section 32 WDV & Companies Act Schedule II Useful Life SLM).
        """
        lower = prompt.lower().strip()

        # 1. Cost extraction
        cost = 0.0
        lakh_match = re.search(r'([\d\.]+)\s*(?:lakh|lacs|lac)', lower)
        crore_match = re.search(r'([\d\.]+)\s*(?:crore|cr)', lower)
        k_match = re.search(r'([\d\.]+)\s*k\b', lower)
        plain_match = re.search(r'(?:rs\.?|inr|for|\$)?\s*([\d,]+(?:\.\d{1,2})?)', lower)

        if lakh_match:
            cost = float(lakh_match.group(1)) * 100000.0
        elif crore_match:
            cost = float(crore_match.group(1)) * 10000000.0
        elif k_match:
            cost = float(k_match.group(1)) * 1000.0
        elif plain_match:
            clean_num = plain_match.group(1).replace(',', '')
            try:
                cost = float(clean_num)
            except ValueError:
                cost = 100000.0
        if cost <= 0:
            cost = 100000.0

        # 2. Categorization
        category = "Computers & IT Hardware"
        wdv_rate = 40.0
        useful_life = 3
        statutory_block = "Block 4: Computers, laptops and software (40% WDV)"

        if any(term in lower for term in ['chair', 'desk', 'table', 'furniture', 'fixture', 'ac\b', 'air conditioner']):
            category = "Office Furniture & Fixtures"
            wdv_rate = 10.0
            useful_life = 10
            statutory_block = "Block 2: Furniture and fittings (10% WDV)"
        elif any(term in lower for term in ['car', 'vehicle', 'automobile', 'motor', 'truck']):
            category = "Commercial Vehicles"
            wdv_rate = 15.0
            useful_life = 8
            statutory_block = "Block 3: Motor vehicles for business (15% WDV)"
        elif any(term in lower for term in ['machinery', 'plant', 'equipment', 'tools', 'generator']):
            category = "Plant & Machinery"
            wdv_rate = 15.0
            useful_life = 15
            statutory_block = "Block 1: General Plant & Machinery (15% WDV)"
        elif any(term in lower for term in ['building', 'office space', 'premise', 'warehouse']):
            category = "Commercial Buildings"
            wdv_rate = 10.0
            useful_life = 30
            statutory_block = "Block 5: Commercial buildings (10% WDV)"
        elif any(term in lower for term in ['software', 'license', 'erp', 'crm', 'trademark']):
            category = "Intangible Assets & Licenses"
            wdv_rate = 25.0
            useful_life = 3
            statutory_block = "Block 6: Intangibles & perpetual software (25% WDV)"

        # 3. 180-day rule check
        less_than_180_days = any(month in lower for month in ['october', 'november', 'december', 'january', 'february', 'march', 'oct', 'nov', 'dec', 'jan', 'feb', 'mar'])
        effective_rate_y1 = (wdv_rate / 2.0) if less_than_180_days else wdv_rate
        depr_y1 = round((cost * effective_rate_y1) / 100.0, 2)
        closing_nbv_y1 = round(cost - depr_y1, 2)

        depr_y2 = round((closing_nbv_y1 * wdv_rate) / 100.0, 2)
        closing_nbv_y2 = round(closing_nbv_y1 - depr_y2, 2)

        depr_y3 = round((closing_nbv_y2 * wdv_rate) / 100.0, 2)
        closing_nbv_y3 = round(closing_nbv_y2 - depr_y3, 2)

        slm_annual = round(cost / useful_life, 2)

        return {
            "success": True,
            "detected_asset": category,
            "cost": cost,
            "statutory_block": statutory_block,
            "income_tax_act_wdv": {
                "standard_rate_percent": wdv_rate,
                "first_year_rate_applied": effective_rate_y1,
                "less_than_180_days_applied": less_than_180_days,
                "year_1_depreciation": depr_y1,
                "year_1_closing_nbv": closing_nbv_y1,
                "year_2_depreciation": depr_y2,
                "year_2_closing_nbv": closing_nbv_y2,
                "year_3_depreciation": depr_y3,
                "year_3_closing_nbv": closing_nbv_y3,
            },
            "companies_act_slm": {
                "useful_life_years": useful_life,
                "annual_depreciation": slm_annual,
                "monthly_depreciation": round(slm_annual / 12.0, 2)
            },
            "tax_statutory_rule": (
                "Section 32 (Income Tax Act): Put to use for < 180 days in the year restricts deduction to 50% of the normal rate."
                if less_than_180_days else
                f"Section 32 (Income Tax Act): Full statutory deduction of {wdv_rate}% is allowed."
            ),
            "suggested_journal_entry": {
                "debit_account": "Depreciation & Amortization Expense",
                "credit_account": "Accumulated Depreciation - Assets",
                "amount": depr_y1
            }
        }
