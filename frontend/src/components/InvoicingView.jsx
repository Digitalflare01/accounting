import React, { useState, useEffect } from 'react';

const INDIAN_STATES = [
  "Andaman and Nicobar Islands", "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar",
  "Chandigarh", "Chhattisgarh", "Dadra and Nagar Haveli and Daman and Diu", "Delhi", "Goa",
  "Gujarat", "Haryana", "Himachal Pradesh", "Jammu and Kashmir", "Jharkhand", "Karnataka",
  "Kerala", "Ladakh", "Lakshadweep", "Madhya Pradesh", "Maharashtra", "Manipur", "Meghalaya",
  "Mizoram", "Nagaland", "Odisha", "Puducherry", "Punjab", "Rajasthan", "Sikkim",
  "Tamil Nadu", "Telangana", "Tripura", "Uttar Pradesh", "Uttarakhand", "West Bengal"
];

// Helper: Convert numbers to Indian Rupees in words
function numberToIndianWords(num) {
  if (!num || isNaN(num) || num <= 0) return 'Zero Rupees Only';
  const a = ['', 'One ', 'Two ', 'Three ', 'Four ', 'Five ', 'Six ', 'Seven ', 'Eight ', 'Nine ', 'Ten ', 'Eleven ', 'Twelve ', 'Thirteen ', 'Fourteen ', 'Fifteen ', 'Sixteen ', 'Seventeen ', 'Eighteen ', 'Nineteen '];
  const b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

  const n = Math.floor(Math.abs(num));
  const paise = Math.round((Math.abs(num) - n) * 100);

  function inWords(val) {
    let str = '';
    if (val > 9999999) {
      str += inWords(Math.floor(val / 10000000)) + 'Crore ';
      val %= 10000000;
    }
    if (val > 99999) {
      str += inWords(Math.floor(val / 100000)) + 'Lakh ';
      val %= 100000;
    }
    if (val > 999) {
      str += inWords(Math.floor(val / 1000)) + 'Thousand ';
      val %= 1000;
    }
    if (val > 99) {
      str += inWords(Math.floor(val / 100)) + 'Hundred ';
      val %= 100;
    }
    if (val > 0) {
      if (val < 20) {
        str += a[val];
      } else {
        str += b[Math.floor(val / 10)] + (val % 10 !== 0 ? ' ' + a[val % 10] : ' ');
      }
    }
    return str;
  }

  let words = inWords(n);
  if (!words.trim()) words = 'Zero ';
  let result = 'Rupees ' + words.trim();
  if (paise > 0) {
    result += ' and ' + inWords(paise).trim() + 'Paise';
  }
  return result + ' Only';
}

export default function InvoicingView({ token, user, onInvoicePosted, onOpenProfile, apiBase }) {
  const [viewMode, setViewMode] = useState('create'); // 'create' | 'history'
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const [successBanner, setSuccessBanner] = useState(null);

  // Business profile details for invoice header & state calculation
  const [businessProfile, setBusinessProfile] = useState(null);

  // Active invoice for Print Preview Modal
  const [printInvoiceData, setPrintInvoiceData] = useState(null);
  const [showPrintModal, setShowPrintModal] = useState(false);

  // Share Dialog state
  const [shareInvoice, setShareInvoice] = useState(null);
  const [copiedNotification, setCopiedNotification] = useState(false);

  // Form State
  const [invoiceNumber, setInvoiceNumber] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [dueDate, setDueDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 15);
    return d.toISOString().split('T')[0];
  });
  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerAddress, setCustomerAddress] = useState('');
  const [customerState, setCustomerState] = useState('Maharashtra');
  const [customerGstin, setCustomerGstin] = useState('');
  const [placeOfSupply, setPlaceOfSupply] = useState('Maharashtra');
  const [paymentStatus, setPaymentStatus] = useState('unpaid');
  const [paymentMode, setPaymentMode] = useState('credit');
  const [notes, setNotes] = useState('Thank you for choosing our business!');
  const [terms, setTerms] = useState('');
  const [postToLedger, setPostToLedger] = useState(true);

  // Line items state
  const [items, setItems] = useState([
    {
      item_description: '',
      hsn_code: '',
      quantity: 1,
      unit: 'Pcs',
      unit_price: 0,
      discount_percent: 0,
      gst_rate: 18
    }
  ]);

  const base = apiBase || (typeof window !== 'undefined' && window.location.pathname.includes('/accounting') ? '/accounting/api' : '/api');

  // Load business profile and invoice list on mount
  useEffect(() => {
    if (token) {
      fetchBusinessProfile();
      fetchInvoices();
      generateNextInvoiceNumber();
    }
  }, [token]);

  const generateNextInvoiceNumber = () => {
    const today = new Date();
    const y = today.getFullYear();
    const m = String(today.getMonth() + 1).padStart(2, '0');
    const rand = Math.floor(1000 + Math.random() * 9000);
    setInvoiceNumber(`INV-${y}${m}-${rand}`);
  };

  const fetchBusinessProfile = async () => {
    try {
      const res = await fetch(`${base}/auth/profile`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (data.success && data.user) {
        setBusinessProfile(data.user);
        if (data.user.state) {
          setPlaceOfSupply(data.user.state);
        }
        if (data.user.invoice_terms && !terms) {
          setTerms(data.user.invoice_terms);
        }
      }
    } catch (err) {
      console.error("Failed to fetch business profile", err);
    }
  };

  const fetchInvoices = async (isBackground = false) => {
    try {
      if (!isBackground) setLoading(true);
      const res = await fetch(`${base}/invoices`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (data.success) {
        setInvoices(data.invoices || []);
      }
    } catch (err) {
      console.warn("Failed to fetch invoices", err);
    } finally {
      if (!isBackground) setLoading(false);
    }
  };

  // Line item change handlers
  const handleItemChange = (index, field, value) => {
    setItems(prev => {
      const next = [...prev];
      next[index] = { ...next[index], [field]: value };
      return next;
    });
  };

  const addItemRow = () => {
    setItems(prev => [
      ...prev,
      {
        item_description: '',
        hsn_code: '',
        quantity: 1,
        unit: 'Pcs',
        unit_price: 0,
        discount_percent: 0,
        gst_rate: 18
      }
    ]);
  };

  const removeItemRow = (index) => {
    if (items.length <= 1) return;
    setItems(prev => prev.filter((_, i) => i !== index));
  };

  // Live Tax Computations
  const sellerState = (businessProfile && businessProfile.state) ? businessProfile.state.toLowerCase().trim() : '';
  const clientState = customerState ? customerState.toLowerCase().trim() : (placeOfSupply ? placeOfSupply.toLowerCase().trim() : '');
  const isInterstate = Boolean(sellerState && clientState && sellerState !== clientState);

  let computedSubtotal = 0;
  let computedCgst = 0;
  let computedSgst = 0;
  let computedIgst = 0;

  const itemCalculations = items.map(item => {
    const qty = Math.max(0, parseFloat(item.quantity) || 0);
    const price = Math.max(0, parseFloat(item.unit_price) || 0);
    const discount = Math.max(0, Math.min(100, parseFloat(item.discount_percent) || 0));
    const gstRate = Math.max(0, parseFloat(item.gst_rate) || 0);

    const baseAmount = qty * price;
    const taxable = baseAmount * (1 - discount / 100);

    let cgst = 0, sgst = 0, igst = 0;
    if (isInterstate) {
      igst = taxable * (gstRate / 100);
    } else {
      cgst = taxable * ((gstRate / 2) / 100);
      sgst = taxable * ((gstRate / 2) / 100);
    }

    const lineTotal = taxable + cgst + sgst + igst;

    computedSubtotal += taxable;
    computedCgst += cgst;
    computedSgst += sgst;
    computedIgst += igst;

    return {
      taxable: taxable.toFixed(2),
      cgst: cgst.toFixed(2),
      sgst: sgst.toFixed(2),
      igst: igst.toFixed(2),
      total: lineTotal.toFixed(2)
    };
  });

  const grandTotal = computedSubtotal + computedCgst + computedSgst + computedIgst;

  // Form Submit: Create Invoice & Immediately Open Print Preview
  const handleSubmitInvoice = async (e) => {
    if (e) e.preventDefault();
    setError(null);

    if (!customerName.trim()) {
      setError('Please enter customer / client name.');
      return;
    }

    const validItems = items.filter(it => it.item_description && it.item_description.trim().length > 0);
    if (validItems.length === 0) {
      setError('Please provide at least one valid line item with description and price.');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        invoice_number: invoiceNumber,
        invoice_date: invoiceDate,
        due_date: dueDate,
        customer_name: customerName,
        customer_phone: customerPhone,
        customer_email: customerEmail,
        customer_address: customerAddress,
        customer_state: customerState,
        customer_gstin: customerGstin,
        place_of_supply: placeOfSupply,
        payment_status: paymentStatus,
        payment_mode: paymentMode,
        notes: notes,
        terms: terms,
        post_to_ledger: postToLedger,
        items: validItems.map(it => ({
          item_description: it.item_description,
          hsn_code: it.hsn_code,
          quantity: parseFloat(it.quantity) || 1,
          unit: it.unit || 'Pcs',
          unit_price: parseFloat(it.unit_price) || 0,
          discount_percent: parseFloat(it.discount_percent) || 0,
          gst_rate: parseFloat(it.gst_rate) || 18
        }))
      };

      const res = await fetch(`${base}/invoices`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      if (!data.success) {
        throw new Error(data.error || 'Failed to generate invoice.');
      }

      // Construct robust invoice print object
      const fullInvoice = data.invoice || {
        id: data.invoice_id,
        invoice_number: data.invoice_number || invoiceNumber,
        invoice_date: data.invoice_date || invoiceDate,
        due_date: dueDate,
        customer_name: customerName,
        customer_phone: customerPhone,
        customer_email: customerEmail,
        customer_address: customerAddress,
        customer_state: customerState,
        customer_gstin: customerGstin,
        place_of_supply: placeOfSupply,
        payment_status: data.payment_status || paymentStatus,
        payment_mode: paymentMode,
        subtotal: computedSubtotal,
        cgst_amount: computedCgst,
        sgst_amount: computedSgst,
        igst_amount: computedIgst,
        total_amount: data.total_amount || grandTotal,
        notes: notes,
        terms: terms
      };

      const fullItems = (data.items && data.items.length > 0) ? data.items : validItems.map(it => {
        const qty = parseFloat(it.quantity) || 1;
        const price = parseFloat(it.unit_price) || 0;
        const disc = parseFloat(it.discount_percent) || 0;
        const gst = parseFloat(it.gst_rate) || 18;
        const taxable = (qty * price) * (1 - disc / 100);
        return {
          item_description: it.item_description,
          hsn_code: it.hsn_code,
          quantity: qty,
          unit: it.unit || 'Pcs',
          unit_price: price,
          discount_percent: disc,
          gst_rate: gst,
          taxable_amount: taxable,
          total_amount: taxable * (1 + gst / 100)
        };
      });

      const fullBusiness = data.business || businessProfile;

      // 1. Immediately open Executive Printable Bill Preview Modal!
      setPrintInvoiceData({
        invoice: fullInvoice,
        items: fullItems,
        business: fullBusiness
      });
      setShowPrintModal(true);

      // 2. Set success banner
      setSuccessBanner({
        invoice_id: data.invoice_id,
        invoice_number: data.invoice_number || invoiceNumber,
        total_amount: data.total_amount || grandTotal,
        posted_to_ledger: data.posted_to_ledger,
        customer_name: customerName,
        customer_phone: customerPhone,
        invoice_date: data.invoice_date || invoiceDate,
        payment_status: data.payment_status || paymentStatus
      });

      // 3. Automatically trigger browser print dialog after modal is rendered
      setTimeout(() => {
        try {
          window.print();
        } catch (printErr) {
          console.warn("Auto print error:", printErr);
        }
      }, 500);

      // 4. Generate next invoice number so subsequent creations have a fresh ID
      generateNextInvoiceNumber();

      // 5. Notify parent & refresh invoice list in background
      if (onInvoicePosted) {
        onInvoicePosted();
      }
      fetchInvoices(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  // Open Printable View for an invoice
  const handleOpenPrint = async (invId) => {
    try {
      setLoading(true);
      const res = await fetch(`${base}/invoices/${invId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (data.success) {
        setPrintInvoiceData({
          invoice: data.invoice,
          items: data.items,
          business: data.business || businessProfile
        });
        setShowPrintModal(true);
      } else {
        alert(data.error || 'Failed to load invoice details');
      }
    } catch (err) {
      alert('Error fetching invoice details: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  // Delete Invoice
  const handleDeleteInvoice = async (invId) => {
    if (!window.confirm('Are you sure you want to delete this invoice? Any associated ledger transactions will also be automatically removed.')) {
      return;
    }

    try {
      setLoading(true);
      const res = await fetch(`${base}/invoices/${invId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (data.success) {
        fetchInvoices();
        if (onInvoicePosted) onInvoicePosted();
      } else {
        alert(data.error || 'Failed to delete invoice');
      }
    } catch (err) {
      alert('Error deleting invoice: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  // Reset form to create new invoice
  const resetForm = () => {
    generateNextInvoiceNumber();
    setCustomerName('');
    setCustomerPhone('');
    setCustomerEmail('');
    setCustomerAddress('');
    setCustomerGstin('');
    setItems([
      {
        item_description: '',
        hsn_code: '',
        quantity: 1,
        unit: 'Pcs',
        unit_price: 0,
        discount_percent: 0,
        gst_rate: 18
      }
    ]);
    setSuccessBanner(null);
    setError(null);
  };

  // Generate WhatsApp Share Link
  const getWhatsAppShareLink = (inv) => {
    try {
      if (!inv) return '#';
      const phone = (inv.customer_phone || '').replace(/[^0-9]/g, '');
      const biz = businessProfile ? (businessProfile.business_name || 'Our Company') : 'ApexLedger';
      const invNum = inv.invoice_number || 'N/A';
      const invDate = inv.invoice_date || new Date().toISOString().split('T')[0];
      const custName = inv.customer_name || 'Valued Customer';
      const totalAmt = parseFloat(inv.total_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
      const status = String(inv.payment_status || 'unpaid').toUpperCase();
      
      let text = `*TAX INVOICE / BILL*\n`;
      text += `From: *${biz}*\n`;
      text += `Invoice No: *${invNum}*\n`;
      text += `Date: ${invDate}\n`;
      text += `Customer: *${custName}*\n`;
      text += `--------------------------------\n`;
      text += `*Total Amount Due: ₹${totalAmt}*\n`;
      text += `Status: ${status}\n`;
      
      if (businessProfile && businessProfile.bank_name) {
        text += `\n*Bank Transfer Details:*\n`;
        text += `Bank: ${businessProfile.bank_name}\n`;
        text += `A/C: ${businessProfile.bank_account_no || ''}\n`;
        text += `IFSC: ${businessProfile.bank_ifsc || ''}\n`;
      }
      if (businessProfile && businessProfile.upi_id) {
        text += `UPI ID: ${businessProfile.upi_id}\n`;
      }
      text += `\nThank you for doing business with us!`;

      const encoded = encodeURIComponent(text);
      return phone ? `https://wa.me/${phone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
    } catch (err) {
      console.warn('WhatsApp link generation fallback:', err);
      return '#';
    }
  };

  // Copy sharable text summary
  const copyBillSummary = (inv) => {
    try {
      if (!inv) return;
      const biz = businessProfile ? (businessProfile.business_name || 'Our Company') : 'ApexLedger';
      const invNum = inv.invoice_number || 'N/A';
      const invDate = inv.invoice_date || new Date().toISOString().split('T')[0];
      const custName = inv.customer_name || 'Valued Customer';
      const totalAmt = parseFloat(inv.total_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
      const status = String(inv.payment_status || 'unpaid').toUpperCase();

      let text = `TAX INVOICE SUMMARY\nFrom: ${biz}\nInvoice No: ${invNum}\nDate: ${invDate}\nCustomer: ${custName}\nTotal Amount: ₹${totalAmt}\nStatus: ${status}`;
      if (businessProfile && businessProfile.bank_account_no) {
        text += `\nBank: ${businessProfile.bank_name || ''}, A/C: ${businessProfile.bank_account_no || ''}, IFSC: ${businessProfile.bank_ifsc || ''}`;
      }
      if (businessProfile && businessProfile.upi_id) {
        text += `\nUPI: ${businessProfile.upi_id}`;
      }
      navigator.clipboard.writeText(text);
      setCopiedNotification(true);
      setTimeout(() => setCopiedNotification(false), 2500);
    } catch (err) {
      console.warn('Copy bill summary fallback:', err);
    }
  };

  return (
    <div className="space-y-6">

      {/* Top Banner & Mode Toggle */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-black text-white flex items-center gap-2">
              <span>🧾 Professional Invoicing & Billing</span>
            </h1>
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
              GST Compliant
            </span>
          </div>
          <p className="text-xs text-slate-400 mt-1">
            Generate executive bills, print invoices, share on WhatsApp, and automatically record entries into dedicated double-entry ledger accounts.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onOpenProfile}
            className="px-3.5 py-2 rounded-xl border border-indigo-500/40 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 text-xs font-bold transition flex items-center gap-1.5 shadow-sm"
          >
            <span>🏢 Business Profile & Logo</span>
          </button>

          <div className="flex bg-slate-950 p-1 rounded-xl border border-slate-800">
            <button
              onClick={() => setViewMode('create')}
              className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${
                viewMode === 'create' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              + New Bill
            </button>
            <button
              onClick={() => { setViewMode('history'); fetchInvoices(); }}
              className={`px-4 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 ${
                viewMode === 'history' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              <span>📋 Invoices List</span>
              <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-800 text-slate-300 font-mono">
                {invoices.length}
              </span>
            </button>
          </div>
        </div>
      </div>

      {/* Copied to clipboard Toast */}
      {copiedNotification && (
        <div className="fixed bottom-6 right-6 z-50 bg-emerald-600 text-white px-4 py-2.5 rounded-xl shadow-2xl text-xs font-bold flex items-center gap-2 animate-bounce">
          <span>✓ Bill summary copied to clipboard!</span>
        </div>
      )}

      {/* SUCCESS BANNER AFTER INVOICE CREATION */}
      {successBanner && (
        <div className="p-5 bg-emerald-950/40 border border-emerald-500/50 rounded-2xl shadow-xl space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-emerald-500/30 pb-3">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-xl text-emerald-400 font-bold">
                ✓
              </div>
              <div>
                <h2 className="text-sm font-bold text-emerald-300 flex items-center gap-2">
                  Invoice Generated Successfully! ({successBanner.invoice_number})
                </h2>
                <p className="text-xs text-emerald-400/80">
                  Total Bill Amount: <span className="font-mono font-bold text-white">₹{parseFloat(successBanner.total_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                  {successBanner.posted_to_ledger && (
                    <span className="ml-2 px-2 py-0.5 rounded bg-emerald-500/20 border border-emerald-500/40 text-[10px] font-bold text-emerald-300">
                      ✓ Posted to Double-Entry Ledger
                    </span>
                  )}
                </p>
              </div>
            </div>

            <button
              onClick={resetForm}
              className="px-3.5 py-1.5 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-750 text-slate-300 text-xs font-semibold"
            >
              + Create Another Bill
            </button>
          </div>

          {/* Quick Actions */}
          <div className="flex flex-wrap items-center gap-3 pt-1">
            <button
              onClick={() => handleOpenPrint(successBanner.invoice_id)}
              className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-lg shadow-indigo-600/30 transition flex items-center gap-2"
            >
              <span>🖨️ Print Professional Bill</span>
            </button>

            <a
              href={getWhatsAppShareLink(successBanner)}
              target="_blank"
              rel="noopener noreferrer"
              className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-lg shadow-emerald-600/30 transition flex items-center gap-2"
            >
              <span>💬 Share on WhatsApp</span>
            </a>

            <button
              onClick={() => copyBillSummary(successBanner)}
              className="px-4 py-2 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition flex items-center gap-1.5"
            >
              <span>📋 Copy Summary</span>
            </button>
          </div>
        </div>
      )}

      {/* ERROR ALERT */}
      {error && (
        <div className="p-4 bg-rose-950/50 border border-rose-500/50 text-rose-300 rounded-xl text-xs flex items-center justify-between">
          <span>⚠️ {error}</span>
          <button onClick={() => setError(null)} className="text-rose-400 font-bold">✕</button>
        </div>
      )}

      {/* ==================================================================== */}
      {/* 1. CREATE INVOICE FORM VIEW */}
      {/* ==================================================================== */}
      {viewMode === 'create' && (
        <form onSubmit={handleSubmitInvoice} className="space-y-6">

          {/* Top Meta: Bill No, Dates, Supply */}
          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <span className="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                <span>📄 Invoice Details</span>
              </span>
              <span className="text-xs text-slate-400">
                Seller State: <strong className="text-indigo-400">{businessProfile?.state || 'Maharashtra'}</strong>
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Invoice Number</label>
                <input
                  type="text"
                  value={invoiceNumber}
                  onChange={(e) => setInvoiceNumber(e.target.value)}
                  placeholder="INV-2026-001"
                  required
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Invoice Date</label>
                <input
                  type="date"
                  value={invoiceDate}
                  onChange={(e) => setInvoiceDate(e.target.value)}
                  required
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Due Date</label>
                <input
                  type="date"
                  value={dueDate}
                  onChange={(e) => setDueDate(e.target.value)}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Place of Supply (State)</label>
                <select
                  value={placeOfSupply}
                  onChange={(e) => setPlaceOfSupply(e.target.value)}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none"
                >
                  {INDIAN_STATES.map((st) => (
                    <option key={st} value={st}>{st}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Customer / Client Information Card */}
          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
            <h2 className="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2 border-b border-slate-800 pb-3">
              <span>👤 Billed To (Client / Customer Information)</span>
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              <div className="lg:col-span-2">
                <label className="block text-xs font-semibold text-slate-400 mb-1">
                  Customer / Business Name <span className="text-rose-400">*</span>
                </label>
                <input
                  type="text"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  placeholder="e.g., Zenith Enterprises India Ltd"
                  required
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">
                  Customer State <span className="text-rose-400">*</span>
                </label>
                <select
                  value={customerState}
                  onChange={(e) => {
                    setCustomerState(e.target.value);
                    setPlaceOfSupply(e.target.value);
                  }}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none"
                >
                  {INDIAN_STATES.map((st) => (
                    <option key={st} value={st}>{st}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">
                  Customer Phone / WhatsApp (For Direct Share)
                </label>
                <input
                  type="text"
                  value={customerPhone}
                  onChange={(e) => setCustomerPhone(e.target.value)}
                  placeholder="+91 91234 56789"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Customer Email</label>
                <input
                  type="email"
                  value={customerEmail}
                  onChange={(e) => setCustomerEmail(e.target.value)}
                  placeholder="accounts@customer.com"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Customer GSTIN (Optional)</label>
                <input
                  type="text"
                  value={customerGstin}
                  onChange={(e) => setCustomerGstin(e.target.value.toUpperCase())}
                  placeholder="27ZYXWV9876E1Z2"
                  maxLength={15}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none uppercase font-mono"
                />
              </div>

              <div className="lg:col-span-3">
                <label className="block text-xs font-semibold text-slate-400 mb-1">Customer Billing Address</label>
                <input
                  type="text"
                  value={customerAddress}
                  onChange={(e) => setCustomerAddress(e.target.value)}
                  placeholder="Office, Plot / Road, Industrial Area, City"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:border-indigo-500 outline-none"
                />
              </div>
            </div>
          </div>

          {/* Line Items Table */}
          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                  📦 Goods / Services Line Items
                </span>
                <span className={`text-[10px] px-2 py-0.5 rounded font-bold ${
                  isInterstate ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-blue-500/20 text-blue-300 border border-blue-500/30'
                }`}>
                  {isInterstate ? 'Interstate Supply (IGST Applicable)' : 'Intrastate Supply (CGST + SGST Applicable)'}
                </span>
              </div>
              <button
                type="button"
                onClick={addItemRow}
                className="px-3 py-1.5 rounded-xl bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 border border-indigo-500/30 text-xs font-bold transition flex items-center gap-1.5"
              >
                <span>+ Add Item Row</span>
              </button>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="border-b border-slate-800 text-slate-400 bg-slate-950/40">
                    <th className="py-2.5 px-3 font-semibold">#</th>
                    <th className="py-2.5 px-3 font-semibold min-w-[220px]">Item / Service Description *</th>
                    <th className="py-2.5 px-3 font-semibold w-24">HSN / SAC</th>
                    <th className="py-2.5 px-3 font-semibold w-20">Qty</th>
                    <th className="py-2.5 px-3 font-semibold w-20">Unit</th>
                    <th className="py-2.5 px-3 font-semibold w-28">Rate (₹)</th>
                    <th className="py-2.5 px-3 font-semibold w-20">Disc %</th>
                    <th className="py-2.5 px-3 font-semibold w-24">GST %</th>
                    <th className="py-2.5 px-3 font-semibold w-28 text-right">Taxable (₹)</th>
                    <th className="py-2.5 px-3 font-semibold w-28 text-right">Total (₹)</th>
                    <th className="py-2.5 px-2 font-semibold w-10 text-center"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60">
                  {items.map((item, idx) => {
                    const calc = itemCalculations[idx];
                    return (
                      <tr key={idx} className="hover:bg-slate-850/50 transition">
                        <td className="py-2 px-3 text-slate-500 font-mono">{idx + 1}</td>
                        <td className="py-2 px-3">
                          <input
                            type="text"
                            value={item.item_description}
                            onChange={(e) => handleItemChange(idx, 'item_description', e.target.value)}
                            placeholder="e.g., Software Development, Hardware, Consulting"
                            required
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white focus:border-indigo-500 outline-none"
                          />
                        </td>
                        <td className="py-2 px-3">
                          <input
                            type="text"
                            value={item.hsn_code}
                            onChange={(e) => handleItemChange(idx, 'hsn_code', e.target.value)}
                            placeholder="998313"
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 text-xs text-white focus:border-indigo-500 outline-none font-mono"
                          />
                        </td>
                        <td className="py-2 px-3">
                          <input
                            type="number"
                            step="any"
                            min="0.01"
                            value={item.quantity}
                            onChange={(e) => handleItemChange(idx, 'quantity', e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 text-xs text-white focus:border-indigo-500 outline-none font-mono"
                          />
                        </td>
                        <td className="py-2 px-3">
                          <select
                            value={item.unit}
                            onChange={(e) => handleItemChange(idx, 'unit', e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-1.5 py-1.5 text-xs text-white focus:border-indigo-500 outline-none"
                          >
                            <option value="Pcs">Pcs</option>
                            <option value="Nos">Nos</option>
                            <option value="Hours">Hours</option>
                            <option value="Month">Month</option>
                            <option value="Service">Service</option>
                            <option value="Kg">Kg</option>
                            <option value="Box">Box</option>
                          </select>
                        </td>
                        <td className="py-2 px-3">
                          <input
                            type="number"
                            step="any"
                            min="0"
                            value={item.unit_price}
                            onChange={(e) => handleItemChange(idx, 'unit_price', e.target.value)}
                            placeholder="0.00"
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 text-xs text-white focus:border-indigo-500 outline-none font-mono text-right"
                          />
                        </td>
                        <td className="py-2 px-3">
                          <input
                            type="number"
                            step="any"
                            min="0"
                            max="100"
                            value={item.discount_percent}
                            onChange={(e) => handleItemChange(idx, 'discount_percent', e.target.value)}
                            placeholder="0"
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 text-xs text-white focus:border-indigo-500 outline-none font-mono text-right"
                          />
                        </td>
                        <td className="py-2 px-3">
                          <select
                            value={item.gst_rate}
                            onChange={(e) => handleItemChange(idx, 'gst_rate', e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 rounded-lg px-1.5 py-1.5 text-xs text-white focus:border-indigo-500 outline-none font-mono"
                          >
                            <option value="0">0%</option>
                            <option value="5">5%</option>
                            <option value="12">12%</option>
                            <option value="18">18%</option>
                            <option value="28">28%</option>
                          </select>
                        </td>
                        <td className="py-2 px-3 text-right font-mono text-slate-300">
                          ₹{calc.taxable}
                        </td>
                        <td className="py-2 px-3 text-right font-mono font-bold text-white">
                          ₹{calc.total}
                        </td>
                        <td className="py-2 px-2 text-center">
                          {items.length > 1 && (
                            <button
                              type="button"
                              onClick={() => removeItemRow(idx)}
                              className="text-slate-500 hover:text-rose-400 text-sm font-bold transition"
                            >
                              ✕
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <button
              type="button"
              onClick={addItemRow}
              className="text-xs text-indigo-400 hover:text-indigo-300 font-semibold py-1 flex items-center gap-1"
            >
              <span>+ Add another line item</span>
            </button>
          </div>

          {/* Grand Totals & Indian Tax Calculation Breakdown */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

            {/* Left: Notes & Terms */}
            <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Payment Status</label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => { setPaymentStatus('unpaid'); setPaymentMode('credit'); }}
                    className={`py-2 px-3 rounded-xl text-xs font-bold border transition ${
                      paymentStatus === 'unpaid'
                        ? 'bg-amber-500/20 border-amber-500/50 text-amber-300'
                        : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-white'
                    }`}
                  >
                    ⏳ Unpaid (Credit Bill)
                  </button>
                  <button
                    type="button"
                    onClick={() => { setPaymentStatus('paid'); setPaymentMode('bank'); }}
                    className={`py-2 px-3 rounded-xl text-xs font-bold border transition ${
                      paymentStatus === 'paid'
                        ? 'bg-emerald-500/20 border-emerald-500/50 text-emerald-300'
                        : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-white'
                    }`}
                  >
                    ✓ Paid (Settled)
                  </button>
                </div>
              </div>

              {paymentStatus === 'paid' && (
                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">Settlement Method</label>
                  <select
                    value={paymentMode}
                    onChange={(e) => setPaymentMode(e.target.value)}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:border-indigo-500 outline-none"
                  >
                    <option value="bank">Bank Transfer / NEFT / RTGS</option>
                    <option value="upi">UPI / Instant Pay</option>
                    <option value="cash">Cash in Hand</option>
                    <option value="cheque">Cheque</option>
                  </select>
                </div>
              )}

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Invoice Notes / Memo</label>
                <textarea
                  rows={2}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Thank you for your business..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:border-indigo-500 outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Terms & Conditions</label>
                <textarea
                  rows={3}
                  value={terms}
                  onChange={(e) => setTerms(e.target.value)}
                  placeholder="Enter specific invoice payment or warranty terms..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white focus:border-indigo-500 outline-none font-mono"
                />
              </div>
            </div>

            {/* Right: Tax Breakdown & Ledger Checkbox */}
            <div className="space-y-4">
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-3">
                <h3 className="text-xs font-bold text-slate-300 uppercase tracking-wider border-b border-slate-800 pb-2">
                  Tax Calculation Breakdown
                </h3>

                <div className="flex justify-between text-xs text-slate-300 py-1">
                  <span>Subtotal (Net Taxable Value):</span>
                  <span className="font-mono font-bold">₹{computedSubtotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                </div>

                {!isInterstate ? (
                  <>
                    <div className="flex justify-between text-xs text-blue-300 py-1">
                      <span>Central GST (CGST):</span>
                      <span className="font-mono font-bold">₹{computedCgst.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between text-xs text-blue-300 py-1">
                      <span>State GST (SGST):</span>
                      <span className="font-mono font-bold">₹{computedSgst.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                  </>
                ) : (
                  <div className="flex justify-between text-xs text-purple-300 py-1">
                    <span>Integrated GST (IGST Interstate):</span>
                    <span className="font-mono font-bold">₹{computedIgst.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                  </div>
                )}

                <div className="border-t border-slate-800 pt-3 flex justify-between items-baseline">
                  <span className="text-sm font-bold text-white">Grand Total:</span>
                  <span className="text-2xl font-black text-indigo-400 font-mono">
                    ₹{grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>

                <div className="p-2.5 bg-slate-950 rounded-xl border border-slate-800/80 text-[11px] text-slate-400 italic">
                  Amount in words: <span className="font-semibold text-slate-200 not-italic">{numberToIndianWords(grandTotal)}</span>
                </div>
              </div>

              {/* CRITICAL USER REQUIREMENT: CHECKBOX FOR DOUBLE-ENTRY ACCOUNTS LEDGER */}
              <div className="bg-gradient-to-r from-indigo-950/60 to-purple-950/60 border-2 border-indigo-500/50 rounded-2xl p-5 shadow-2xl relative overflow-hidden">
                <div className="flex items-start gap-3">
                  <input
                    type="checkbox"
                    id="postToLedgerCheck"
                    checked={postToLedger}
                    onChange={(e) => setPostToLedger(e.target.checked)}
                    className="w-5 h-5 mt-0.5 rounded border-indigo-400 text-indigo-600 focus:ring-indigo-500 cursor-pointer accent-indigo-600"
                  />
                  <div className="space-y-1">
                    <label htmlFor="postToLedgerCheck" className="text-xs sm:text-sm font-black text-white cursor-pointer select-none flex items-center gap-2">
                      <span>Take bill transaction record into dedicated accounts</span>
                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                        Double-Entry Ready
                      </span>
                    </label>
                    <p className="text-[11px] text-slate-300 leading-relaxed">
                      Automatically posts balanced double-entry accounting records into your General Ledger:
                    </p>
                    <ul className="text-[10px] text-indigo-200 space-y-0.5 font-mono list-disc list-inside">
                      <li><strong>Debit:</strong> {paymentStatus === 'paid' ? (paymentMode === 'cash' ? '1010 Cash in Hand' : '1020 Bank Operating Account') : '1100 Accounts Receivable (Debtors)'} for ₹{grandTotal.toFixed(2)}</li>
                      <li><strong>Credit:</strong> 4030 Sales Revenue for ₹{computedSubtotal.toFixed(2)}</li>
                      <li><strong>Credit:</strong> Output Tax ({isInterstate ? '2130 Output IGST' : '2110 CGST + 2120 SGST'}) for ₹{(computedCgst + computedSgst + computedIgst).toFixed(2)}</li>
                    </ul>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex items-center gap-3">
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-1 py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-black text-sm shadow-xl shadow-indigo-600/30 transition flex items-center justify-center gap-2 cursor-pointer disabled:opacity-75 disabled:cursor-not-allowed"
                >
                  {submitting ? (
                    <>
                      <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                      <span>Saving & Opening Preview...</span>
                    </>
                  ) : (
                    <>
                      <span>💾 Save & Generate Invoice</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </form>
      )}

      {/* ==================================================================== */}
      {/* 2. INVOICE HISTORY TABLE VIEW */}
      {/* ==================================================================== */}
      {viewMode === 'history' && (
        <div className="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden space-y-4 p-5">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
            <div>
              <h2 className="text-sm font-bold text-white flex items-center gap-2">
                <span>📋 Generated Invoices & Billing Archive</span>
              </h2>
              <p className="text-xs text-slate-400">Total Invoices: {invoices.length} &bull; Quick print, share, or audit ledger postings.</p>
            </div>
            <button
              onClick={() => setViewMode('create')}
              className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition flex items-center gap-1.5 shadow"
            >
              <span>+ Create New Bill</span>
            </button>
          </div>

          {invoices.length === 0 ? (
            <div className="text-center py-16 space-y-3">
              <span className="text-4xl text-slate-600">🧾</span>
              <p className="text-sm text-slate-400">No invoices generated yet.</p>
              <button
                onClick={() => setViewMode('create')}
                className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition"
              >
                Create First Invoice
              </button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="border-b border-slate-800 text-slate-400 bg-slate-950/40">
                    <th className="py-3 px-4 font-semibold">Invoice #</th>
                    <th className="py-3 px-4 font-semibold">Date</th>
                    <th className="py-3 px-4 font-semibold">Customer Name</th>
                    <th className="py-3 px-4 font-semibold text-right">Amount (₹)</th>
                    <th className="py-3 px-4 font-semibold text-center">Status</th>
                    <th className="py-3 px-4 font-semibold text-center">Ledger Posted</th>
                    <th className="py-3 px-4 font-semibold text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60">
                  {invoices.map((inv) => (
                    <tr key={inv.id} className="hover:bg-slate-850/50 transition">
                      <td className="py-3 px-4 font-mono font-bold text-indigo-300">
                        {inv.invoice_number}
                      </td>
                      <td className="py-3 px-4 text-slate-400 font-mono">
                        {inv.invoice_date}
                      </td>
                      <td className="py-3 px-4">
                        <div className="font-semibold text-white">{inv.customer_name}</div>
                        {inv.customer_phone && <div className="text-[11px] text-slate-500">{inv.customer_phone}</div>}
                      </td>
                      <td className="py-3 px-4 text-right font-mono font-bold text-white">
                        ₹{parseFloat(inv.total_amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                      </td>
                      <td className="py-3 px-4 text-center">
                        <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                          inv.payment_status === 'paid'
                            ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                            : 'bg-amber-500/20 text-amber-300 border border-amber-500/30'
                        }`}>
                          {inv.payment_status}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-center">
                        {inv.posted_to_ledger == 1 ? (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                            ✓ In Ledger
                          </span>
                        ) : (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400">
                            Draft
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => handleOpenPrint(inv.id)}
                            title="Print Professional Invoice"
                            className="p-1.5 rounded-lg border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 text-xs font-bold transition flex items-center gap-1"
                          >
                            <span>🖨️ Print</span>
                          </button>

                          <a
                            href={getWhatsAppShareLink(inv)}
                            target="_blank"
                            rel="noopener noreferrer"
                            title="Share on WhatsApp"
                            className="p-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 text-xs font-bold transition flex items-center gap-1"
                          >
                            <span>💬 Share</span>
                          </a>

                          <button
                            onClick={() => copyBillSummary(inv)}
                            title="Copy Bill Summary"
                            className="p-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition"
                          >
                            📋
                          </button>

                          <button
                            onClick={() => handleDeleteInvoice(inv.id)}
                            title="Delete Invoice and Ledger Entries"
                            className="p-1.5 rounded-lg border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 text-xs font-bold transition"
                          >
                            🗑️
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ==================================================================== */}
      {/* 3. EXECUTIVE PRINTABLE BILL MODAL (A4 PRINTABLE STYLED) */}
      {/* ==================================================================== */}
      {showPrintModal && printInvoiceData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/80 backdrop-blur-sm overflow-y-auto">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-4xl max-h-[95vh] flex flex-col shadow-2xl overflow-hidden">
            
            {/* Modal Actions Bar (hidden in print) */}
            <div className="px-6 py-3 border-b border-slate-800 flex items-center justify-between bg-slate-950/90 no-print">
              <div className="flex items-center gap-2">
                <span className="text-sm font-bold text-white">Print Professional Invoice</span>
                <span className="text-xs text-slate-400 font-mono">({printInvoiceData.invoice?.invoice_number || ''})</span>
              </div>
              <div className="flex items-center gap-3">
                <button
                  onClick={() => window.print()}
                  className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-lg shadow-indigo-600/30 transition flex items-center gap-1.5"
                >
                  <span>🖨️ Print Now / Save PDF</span>
                </button>
                <a
                  href={getWhatsAppShareLink(printInvoiceData.invoice || {})}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-lg shadow-emerald-600/30 transition flex items-center gap-1.5"
                >
                  <span>💬 WhatsApp</span>
                </a>
                <button
                  onClick={() => setShowPrintModal(false)}
                  className="w-8 h-8 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center text-sm font-bold transition"
                >
                  ✕
                </button>
              </div>
            </div>

            {/* Print Document Body */}
            <div className="flex-1 overflow-y-auto p-4 sm:p-8 bg-white text-black font-sans leading-tight print-area">
              
              {/* Header Box */}
              <div className="flex justify-between items-start border-b-2 border-slate-900 pb-4 mb-4">
                <div className="space-y-1.5 max-w-[60%]">
                  {printInvoiceData.business?.logo_data && (
                    <img
                      src={printInvoiceData.business.logo_data}
                      alt="Logo"
                      className="max-h-16 max-w-[180px] object-contain mb-2"
                    />
                  )}
                  <h1 className="text-xl font-black uppercase text-slate-900 tracking-tight">
                    {printInvoiceData.business?.business_name || 'Business Name'}
                  </h1>
                  {printInvoiceData.business?.owner_name && (
                    <p className="text-xs text-slate-700">Proprietor: {printInvoiceData.business.owner_name}</p>
                  )}
                  {printInvoiceData.business?.address && (
                    <p className="text-xs text-slate-600 whitespace-pre-line leading-snug">
                      {printInvoiceData.business.address}, {printInvoiceData.business.city} {printInvoiceData.business.pincode}
                    </p>
                  )}
                  <p className="text-xs text-slate-700">
                    State: <strong>{printInvoiceData.business?.state || 'Maharashtra'}</strong>
                    {printInvoiceData.business?.phone && <> &bull; Phone: {printInvoiceData.business.phone}</>}
                  </p>
                  {printInvoiceData.business?.gst_number && (
                    <p className="text-xs font-bold text-slate-900 font-mono">
                      GSTIN: {printInvoiceData.business.gst_number}
                    </p>
                  )}
                  {printInvoiceData.business?.pan_number && (
                    <p className="text-xs font-mono text-slate-700">
                      PAN: {printInvoiceData.business.pan_number}
                    </p>
                  )}
                </div>

                <div className="text-right space-y-1">
                  <div className="inline-block px-3 py-1 bg-slate-900 text-white font-black text-sm uppercase tracking-wider rounded">
                    TAX INVOICE
                  </div>
                  <div className="pt-2 text-xs space-y-0.5">
                    <p>Invoice No: <strong className="font-mono">{printInvoiceData.invoice?.invoice_number || 'N/A'}</strong></p>
                    <p>Date: <strong className="font-mono">{printInvoiceData.invoice?.invoice_date || ''}</strong></p>
                    {printInvoiceData.invoice?.due_date && (
                      <p>Due Date: <span className="font-mono">{printInvoiceData.invoice.due_date}</span></p>
                    )}
                    <p>Place of Supply: <strong>{printInvoiceData.invoice?.place_of_supply || printInvoiceData.business?.state || ''}</strong></p>
                    <p>Payment: <strong className="uppercase">{printInvoiceData.invoice?.payment_status || 'Unpaid'}</strong></p>
                  </div>
                </div>
              </div>

              {/* Billed To Box */}
              <div className="grid grid-cols-2 gap-4 border border-slate-300 rounded p-3 mb-4 text-xs bg-slate-50">
                <div>
                  <span className="font-bold text-slate-500 uppercase tracking-wider text-[10px] block mb-1">Billed To (Client):</span>
                  <h3 className="font-bold text-sm text-slate-900">{printInvoiceData.invoice?.customer_name || 'Customer'}</h3>
                  {printInvoiceData.invoice?.customer_address && (
                    <p className="text-slate-700 whitespace-pre-line mt-0.5">{printInvoiceData.invoice.customer_address}</p>
                  )}
                  <p className="text-slate-700 mt-0.5">
                    State: <strong>{printInvoiceData.invoice?.customer_state || printInvoiceData.invoice?.place_of_supply || 'N/A'}</strong>
                  </p>
                  {printInvoiceData.invoice?.customer_phone && (
                    <p className="text-slate-700">Phone: {printInvoiceData.invoice.customer_phone}</p>
                  )}
                </div>

                <div className="text-right space-y-1">
                  {printInvoiceData.invoice?.customer_gstin && (
                    <p>Customer GSTIN: <strong className="font-mono">{printInvoiceData.invoice.customer_gstin}</strong></p>
                  )}
                  {printInvoiceData.invoice?.customer_email && (
                    <p>Email: {printInvoiceData.invoice.customer_email}</p>
                  )}
                </div>
              </div>

              {/* Line Items Table */}
              <table className="w-full border-collapse border border-slate-300 text-xs mb-4">
                <thead>
                  <tr className="bg-slate-100 text-slate-900 font-bold border-b border-slate-300">
                    <th className="border border-slate-300 py-2 px-2 text-center w-8">#</th>
                    <th className="border border-slate-300 py-2 px-3 text-left">Item / Description</th>
                    <th className="border border-slate-300 py-2 px-2 text-center w-16">HSN/SAC</th>
                    <th className="border border-slate-300 py-2 px-2 text-center w-12">Qty</th>
                    <th className="border border-slate-300 py-2 px-2 text-center w-12">Unit</th>
                    <th className="border border-slate-300 py-2 px-2 text-right w-20">Rate (₹)</th>
                    <th className="border border-slate-300 py-2 px-2 text-right w-12">Disc %</th>
                    <th className="border border-slate-300 py-2 px-2 text-right w-20">Taxable (₹)</th>
                    <th className="border border-slate-300 py-2 px-2 text-right w-14">GST %</th>
                    <th className="border border-slate-300 py-2 px-3 text-right w-24">Total (₹)</th>
                  </tr>
                </thead>
                <tbody>
                  {(printInvoiceData.items || []).map((it, idx) => (
                    <tr key={idx} className="border-b border-slate-200">
                      <td className="border border-slate-300 py-1.5 px-2 text-center text-slate-600">{idx + 1}</td>
                      <td className="border border-slate-300 py-1.5 px-3 font-medium text-slate-900">{it.item_description}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-center font-mono text-slate-700">{it.hsn_code || '-'}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-center font-mono">{parseFloat(it.quantity || 1)}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-center">{it.unit || 'Pcs'}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-right font-mono">{parseFloat(it.unit_price || 0).toFixed(2)}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-right font-mono">{parseFloat(it.discount_percent || 0)}%</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-right font-mono">{parseFloat(it.taxable_amount || 0).toFixed(2)}</td>
                      <td className="border border-slate-300 py-1.5 px-2 text-right font-mono">{parseFloat(it.gst_rate || 0)}%</td>
                      <td className="border border-slate-300 py-1.5 px-3 text-right font-mono font-bold text-slate-900">
                        {parseFloat(it.total_amount || 0).toFixed(2)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* Totals & Tax Summary Block */}
              <div className="grid grid-cols-2 gap-4 mb-4 text-xs">
                
                {/* Left: Bank Details for Payment */}
                <div className="border border-slate-300 rounded p-3 bg-slate-50 space-y-1">
                  <span className="font-bold text-slate-800 uppercase tracking-wider text-[10px] block mb-1">
                    Bank & Settlement Coordinates:
                  </span>
                  {printInvoiceData.business?.bank_name ? (
                    <>
                      <p>Bank: <strong>{printInvoiceData.business.bank_name}</strong></p>
                      <p>Account No: <strong className="font-mono">{printInvoiceData.business.bank_account_no}</strong></p>
                      <p>IFSC Code: <strong className="font-mono">{printInvoiceData.business.bank_ifsc}</strong></p>
                      {printInvoiceData.business.bank_branch && <p>Branch: {printInvoiceData.business.bank_branch}</p>}
                    </>
                  ) : (
                    <p className="text-slate-500 italic">No bank details added in profile.</p>
                  )}
                  {printInvoiceData.business?.upi_id && (
                    <p className="pt-1 border-t border-slate-200 mt-1">
                      UPI ID (VPA): <strong className="font-mono text-indigo-700">{printInvoiceData.business.upi_id}</strong>
                    </p>
                  )}
                </div>

                {/* Right: Calculations Table */}
                <div className="border border-slate-300 rounded p-3 space-y-1 text-right">
                  <div className="flex justify-between py-0.5">
                    <span className="text-slate-600">Subtotal (Taxable Amount):</span>
                    <span className="font-mono font-bold">₹{parseFloat(printInvoiceData.invoice?.subtotal || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                  </div>
                  {parseFloat(printInvoiceData.invoice?.cgst_amount || 0) > 0 && (
                    <div className="flex justify-between py-0.5">
                      <span className="text-slate-600">Central GST (CGST):</span>
                      <span className="font-mono">₹{parseFloat(printInvoiceData.invoice?.cgst_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                  )}
                  {parseFloat(printInvoiceData.invoice?.sgst_amount || 0) > 0 && (
                    <div className="flex justify-between py-0.5">
                      <span className="text-slate-600">State GST (SGST):</span>
                      <span className="font-mono">₹{parseFloat(printInvoiceData.invoice?.sgst_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                  )}
                  {parseFloat(printInvoiceData.invoice?.igst_amount || 0) > 0 && (
                    <div className="flex justify-between py-0.5">
                      <span className="text-slate-600">Integrated GST (IGST):</span>
                      <span className="font-mono">₹{parseFloat(printInvoiceData.invoice?.igst_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                    </div>
                  )}
                  <div className="flex justify-between py-1.5 border-t-2 border-slate-900 text-sm font-black text-slate-900">
                    <span>Grand Total Due:</span>
                    <span className="font-mono">₹{parseFloat(printInvoiceData.invoice?.total_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                  </div>
                  <div className="text-[10px] text-slate-600 italic text-left pt-1 border-t border-slate-200">
                    Amount in words: <strong className="not-italic text-slate-900">{numberToIndianWords(parseFloat(printInvoiceData.invoice?.total_amount || 0))}</strong>
                  </div>
                </div>
              </div>

              {/* Terms & Conditions + Signatory */}
              <div className="grid grid-cols-2 gap-4 pt-4 border-t border-slate-300 text-xs">
                <div className="space-y-1">
                  <span className="font-bold text-[10px] uppercase tracking-wider text-slate-700">Terms & Conditions:</span>
                  <p className="text-[11px] text-slate-600 whitespace-pre-line leading-relaxed">
                    {printInvoiceData.invoice?.terms || printInvoiceData.business?.invoice_terms || "1. Payment is due within 15 days.\n2. Overdue bills attract 18% p.a. interest.\n3. Subject to local jurisdiction."}
                  </p>
                </div>

                <div className="flex flex-col items-end justify-between h-28 text-right">
                  <span className="font-bold text-xs text-slate-900">
                    {printInvoiceData.business?.business_name}
                  </span>
                  <div className="border-t border-slate-900 pt-1 w-48 text-center text-[10px] text-slate-700">
                    {printInvoiceData.business?.signature_title || 'Authorized Signatory'}
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>
      )}

    </div>
  );
}
