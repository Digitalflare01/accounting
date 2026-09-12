import React, { useState, useEffect } from 'react';

const INDIAN_STATES = [
  "Andaman and Nicobar Islands", "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar",
  "Chandigarh", "Chhattisgarh", "Dadra and Nagar Haveli and Daman and Diu", "Delhi", "Goa",
  "Gujarat", "Haryana", "Himachal Pradesh", "Jammu and Kashmir", "Jharkhand", "Karnataka",
  "Kerala", "Ladakh", "Lakshadweep", "Madhya Pradesh", "Maharashtra", "Manipur", "Meghalaya",
  "Mizoram", "Nagaland", "Odisha", "Puducherry", "Punjab", "Rajasthan", "Sikkim",
  "Tamil Nadu", "Telangana", "Tripura", "Uttar Pradesh", "Uttarakhand", "West Bengal"
];

export default function BusinessProfileModal({ isOpen, onClose, token, user, onProfileUpdated, apiBase }) {
  const [activeSubTab, setActiveSubTab] = useState('identity');
  const [loading, setLoading] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [error, setError] = useState(null);

  // Profile Form States
  const [businessName, setBusinessName] = useState('');
  const [ownerName, setOwnerName] = useState('');
  const [gstNumber, setGstNumber] = useState('');
  const [panNumber, setPanNumber] = useState('');
  const [phone, setPhone] = useState('');
  const [address, setAddress] = useState('');
  const [city, setCity] = useState('');
  const [state, setState] = useState('Maharashtra');
  const [pincode, setPincode] = useState('');
  const [bankName, setBankName] = useState('');
  const [bankAccountNo, setBankAccountNo] = useState('');
  const [bankIfsc, setBankIfsc] = useState('');
  const [bankBranch, setBankBranch] = useState('');
  const [upiId, setUpiId] = useState('');
  const [logoData, setLogoData] = useState('');
  const [invoiceTerms, setInvoiceTerms] = useState('');
  const [signatureTitle, setSignatureTitle] = useState('Authorized Signatory');

  const base = apiBase || (typeof window !== 'undefined' && window.location.pathname.includes('/accounting') ? '/accounting/api' : '/api');

  // Load existing profile from user prop or fetch from server
  useEffect(() => {
    if (isOpen && token) {
      fetchProfile();
    }
  }, [isOpen, token]);

  const fetchProfile = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await fetch(`${base}/auth/profile`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      const data = await res.json();
      if (data.success && data.user) {
        populateFields(data.user);
      } else if (user) {
        populateFields(user);
      }
    } catch (err) {
      if (user) populateFields(user);
    } finally {
      setLoading(false);
    }
  };

  const populateFields = (u) => {
    setBusinessName(u.business_name || '');
    setOwnerName(u.owner_name || '');
    setGstNumber(u.gst_number || '');
    setPanNumber(u.pan_number || '');
    setPhone(u.phone || '');
    setAddress(u.address || '');
    setCity(u.city || '');
    setState(u.state || 'Maharashtra');
    setPincode(u.pincode || '');
    setBankName(u.bank_name || '');
    setBankAccountNo(u.bank_account_no || '');
    setBankIfsc(u.bank_ifsc || '');
    setBankBranch(u.bank_branch || '');
    setUpiId(u.upi_id || '');
    setLogoData(u.logo_data || '');
    setInvoiceTerms(u.invoice_terms || "1. Payment is due within 15 days of invoice date.\n2. Overdue payments will incur 18% annual interest.\n3. Goods/Services once delivered are non-refundable.\n4. All disputes subject to local jurisdiction.");
    setSignatureTitle(u.signature_title || 'Authorized Signatory');
  };

  // Handle Logo Upload via FileReader
  const handleLogoUpload = (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
      setError('Logo image must be under 2MB.');
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      setLogoData(reader.result);
      setError(null);
    };
    reader.onerror = () => {
      setError('Failed to read image file.');
    };
    reader.readAsDataURL(file);
  };

  const handleSave = async (e) => {
    if (e) e.preventDefault();
    setLoading(true);
    setError(null);
    setSaveSuccess(false);

    try {
      const payload = {
        business_name: businessName,
        owner_name: ownerName,
        gst_number: gstNumber,
        pan_number: panNumber,
        phone: phone,
        address: address,
        city: city,
        state: state,
        pincode: pincode,
        bank_name: bankName,
        bank_account_no: bankAccountNo,
        bank_ifsc: bankIfsc,
        bank_branch: bankBranch,
        upi_id: upiId,
        logo_data: logoData,
        invoice_terms: invoiceTerms,
        signature_title: signatureTitle
      };

      const res = await fetch(`${base}/auth/profile`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      if (!data.success) {
        throw new Error(data.error || 'Failed to update business profile.');
      }

      setSaveSuccess(true);
      if (onProfileUpdated && data.user) {
        onProfileUpdated(data.user);
      }
      setTimeout(() => setSaveSuccess(false), 3000);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm overflow-y-auto">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
        
        {/* Modal Header */}
        <div className="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/80">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center text-xl text-indigo-400 font-bold">
              🏢
            </div>
            <div>
              <h2 className="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                Business & Enterprise Profile
                <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 font-semibold border border-emerald-500/30">
                  Used on Bills & Tax Invoices
                </span>
              </h2>
              <p className="text-xs text-slate-400">Configure logo, registered business address, banking, and invoice footer terms.</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="w-8 h-8 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center text-sm font-bold transition"
          >
            ✕
          </button>
        </div>

        {/* Sub-tabs Navigation */}
        <div className="flex border-b border-slate-800 bg-slate-950/50 px-6 gap-2 text-xs font-semibold overflow-x-auto">
          <button
            onClick={() => setActiveSubTab('identity')}
            className={`py-3 px-4 border-b-2 transition flex items-center gap-1.5 whitespace-nowrap ${
              activeSubTab === 'identity' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <span>🏷️ Identity & Logo</span>
          </button>
          <button
            onClick={() => setActiveSubTab('location')}
            className={`py-3 px-4 border-b-2 transition flex items-center gap-1.5 whitespace-nowrap ${
              activeSubTab === 'location' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <span>📍 Address & Contact</span>
          </button>
          <button
            onClick={() => setActiveSubTab('banking')}
            className={`py-3 px-4 border-b-2 transition flex items-center gap-1.5 whitespace-nowrap ${
              activeSubTab === 'banking' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <span>💳 Bank & UPI Details</span>
          </button>
          <button
            onClick={() => setActiveSubTab('terms')}
            className={`py-3 px-4 border-b-2 transition flex items-center gap-1.5 whitespace-nowrap ${
              activeSubTab === 'terms' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-white'
            }`}
          >
            <span>📜 Invoice Terms & Signatory</span>
          </button>
        </div>

        {/* Status Messages */}
        {error && (
          <div className="mx-6 mt-4 p-3 bg-rose-950/50 border border-rose-500/50 text-rose-300 rounded-xl text-xs flex items-center justify-between">
            <span>⚠️ {error}</span>
            <button onClick={() => setError(null)} className="text-rose-400 font-bold">✕</button>
          </div>
        )}

        {saveSuccess && (
          <div className="mx-6 mt-4 p-3 bg-emerald-950/50 border border-emerald-500/50 text-emerald-300 rounded-xl text-xs flex items-center gap-2">
            <span>✓ Business profile updated successfully! All future invoices will use these details.</span>
          </div>
        )}

        {/* Tab Content Form */}
        <form onSubmit={handleSave} className="flex-1 overflow-y-auto p-6 space-y-5">

          {/* 1. IDENTITY & LOGO */}
          {activeSubTab === 'identity' && (
            <div className="space-y-5">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    Registered Business / Trade Name <span className="text-rose-400">*</span>
                  </label>
                  <input
                    type="text"
                    value={businessName}
                    onChange={(e) => setBusinessName(e.target.value)}
                    placeholder="e.g., Apex Global Tech Solutions Pvt Ltd"
                    required
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                  <p className="text-[11px] text-slate-500 mt-1">Appears prominently at the top of printed bills.</p>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    Proprietor / Managing Director Name
                  </label>
                  <input
                    type="text"
                    value={ownerName}
                    onChange={(e) => setOwnerName(e.target.value)}
                    placeholder="e.g., Rajesh Sharma"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    GSTIN (GST Identification Number)
                  </label>
                  <input
                    type="text"
                    value={gstNumber}
                    onChange={(e) => setGstNumber(e.target.value.toUpperCase())}
                    placeholder="e.g., 27ABCDE1234F1Z5"
                    maxLength={15}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none uppercase font-mono"
                  />
                  <p className="text-[11px] text-slate-500 mt-1">15-digit alphanumeric Indian GST Number.</p>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    PAN (Permanent Account Number)
                  </label>
                  <input
                    type="text"
                    value={panNumber}
                    onChange={(e) => setPanNumber(e.target.value.toUpperCase())}
                    placeholder="e.g., ABCDE1234F"
                    maxLength={10}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none uppercase font-mono"
                  />
                </div>
              </div>

              {/* Logo Section */}
              <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-xs font-bold text-white uppercase tracking-wider">Business Brand Logo</h3>
                    <p className="text-[11px] text-slate-400">Upload your company logo (PNG, JPG, SVG up to 2MB). Printed at the top of your bill.</p>
                  </div>
                  {logoData && (
                    <button
                      type="button"
                      onClick={() => setLogoData('')}
                      className="text-[11px] text-rose-400 hover:underline font-semibold"
                    >
                      Remove Logo
                    </button>
                  )}
                </div>

                <div className="flex flex-col sm:flex-row items-center gap-4 pt-2">
                  <div className="w-24 h-24 rounded-xl border-2 border-dashed border-slate-700 bg-slate-900 flex items-center justify-center overflow-hidden shrink-0 shadow-inner">
                    {logoData ? (
                      <img src={logoData} alt="Business Logo" className="w-full h-full object-contain p-1" />
                    ) : (
                      <span className="text-3xl text-slate-600">🖼️</span>
                    )}
                  </div>

                  <div className="flex-1 w-full space-y-2">
                    <label className="inline-block cursor-pointer px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-indigo-600/20 transition">
                      <span>📁 Select Image File</span>
                      <input
                        type="file"
                        accept="image/png, image/jpeg, image/webp, image/svg+xml"
                        onChange={handleLogoUpload}
                        className="hidden"
                      />
                    </label>
                    <p className="text-[11px] text-slate-500">Supported formats: PNG, JPG, WebP, SVG. Transparent background recommended for invoices.</p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* 2. LOCATION & CONTACT */}
          {activeSubTab === 'location' && (
            <div className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    Contact Phone / WhatsApp Number
                  </label>
                  <input
                    type="text"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder="+91 98765 43210"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    State / Union Territory <span className="text-rose-400">*</span>
                  </label>
                  <select
                    value={state}
                    onChange={(e) => setState(e.target.value)}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  >
                    {INDIAN_STATES.map((st) => (
                      <option key={st} value={st}>{st}</option>
                    ))}
                  </select>
                  <p className="text-[11px] text-slate-500 mt-1">Used to determine Intrastate (CGST+SGST) vs Interstate (IGST) tax rules.</p>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Full Registered Street Address
                </label>
                <textarea
                  rows={2}
                  value={address}
                  onChange={(e) => setAddress(e.target.value)}
                  placeholder="Floor, Building, Road, Industrial Estate / Area"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">City / District</label>
                  <input
                    type="text"
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                    placeholder="e.g., Mumbai, Bengaluru, Pune"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">Postal PIN Code</label>
                  <input
                    type="text"
                    value={pincode}
                    onChange={(e) => setPincode(e.target.value)}
                    placeholder="e.g., 400051"
                    maxLength={6}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                  />
                </div>
              </div>
            </div>
          )}

          {/* 3. BANKING & UPI */}
          {activeSubTab === 'banking' && (
            <div className="space-y-4">
              <div className="p-3 bg-indigo-950/40 border border-indigo-500/30 rounded-xl text-xs text-indigo-200">
                💡 Bank and UPI payment coordinates are printed directly on customer invoices, enabling instant settlements via NEFT, RTGS, IMPS, or QR code scan.
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">Bank Name</label>
                  <input
                    type="text"
                    value={bankName}
                    onChange={(e) => setBankName(e.target.value)}
                    placeholder="e.g., HDFC Bank, State Bank of India, ICICI Bank"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">Account Number</label>
                  <input
                    type="text"
                    value={bankAccountNo}
                    onChange={(e) => setBankAccountNo(e.target.value)}
                    placeholder="e.g., 50200012345678"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">IFSC Code</label>
                  <input
                    type="text"
                    value={bankIfsc}
                    onChange={(e) => setBankIfsc(e.target.value.toUpperCase())}
                    placeholder="e.g., HDFC0001234"
                    maxLength={11}
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none uppercase font-mono"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">Branch Name</label>
                  <input
                    type="text"
                    value={bankBranch}
                    onChange={(e) => setBankBranch(e.target.value)}
                    placeholder="e.g., Bandra Kurla Complex Branch"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  />
                </div>

                <div className="md:col-span-2">
                  <label className="block text-xs font-semibold text-slate-300 mb-1">UPI ID (VPA) for Instant Scan / Transfer</label>
                  <input
                    type="text"
                    value={upiId}
                    onChange={(e) => setUpiId(e.target.value)}
                    placeholder="e.g., apextech@okhdfcbank or 9876543210@paytm"
                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none font-mono"
                  />
                  <p className="text-[11px] text-slate-500 mt-1">Generates one-tap payment link in WhatsApp bill shares.</p>
                </div>
              </div>
            </div>
          )}

          {/* 4. INVOICE TERMS & SIGNATORY */}
          {activeSubTab === 'terms' && (
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Default Invoice Terms & Conditions
                </label>
                <textarea
                  rows={5}
                  value={invoiceTerms}
                  onChange={(e) => setInvoiceTerms(e.target.value)}
                  placeholder="Enter terms, payment period, interest on delayed dues, warranty, and dispute conditions..."
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:border-indigo-500 outline-none font-mono leading-relaxed"
                />
                <p className="text-[11px] text-slate-500 mt-1">Appears at the bottom of printed invoices. Can also be overridden per bill.</p>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Signature Label / Signatory Title
                </label>
                <input
                  type="text"
                  value={signatureTitle}
                  onChange={(e) => setSignatureTitle(e.target.value)}
                  placeholder="e.g., For Apex Global Tech Solutions Pvt Ltd / Authorized Signatory"
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                />
              </div>
            </div>
          )}

          {/* Footer Actions */}
          <div className="pt-4 border-t border-slate-800 flex items-center justify-between">
            <button
              type="button"
              onClick={onClose}
              className="px-5 py-2.5 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition"
            >
              Close
            </button>

            <button
              type="submit"
              disabled={loading}
              className="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-lg shadow-indigo-600/30 transition flex items-center gap-2"
            >
              {loading ? (
                <>
                  <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                  <span>Saving Profile...</span>
                </>
              ) : (
                <>
                  <span>💾 Save Business Profile</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
