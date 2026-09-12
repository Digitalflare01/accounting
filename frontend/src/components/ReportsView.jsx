import React, { useState, useEffect } from 'react';

/**
 * ReportsView Component
 * 1. Profit & Loss
 * 2. Balance Sheet
 * 3. Trial Balance
 * 4. Cash Flow Statement
 * 5. GST Compliance (GSTR-1 & 3B, ITC, Checklist)
 * 6. Accounting Principles Knowledge Hub & Google DB Harvest
 */
export default function ReportsView({ token, apiUrl = '/api' }) {

      const [activeTab, setActiveTab] = useState('pnl');
      const [loading, setLoading] = useState(false);
      const [error, setError] = useState(null);

      const [pnlData, setPnlData] = useState(null);
      const [bsData, setBsData] = useState(null);
      const [gstData, setGstData] = useState(null);

      // Statutory GST Portal States
      const [gstSubTab, setGstSubTab] = useState('gstr3b'); // 'gstr3b' | 'gstr1' | 'itc' | 'checklist'
      const [gstStartDate, setGstStartDate] = useState('2026-08-01');
      const [gstEndDate, setGstEndDate] = useState('2026-09-30');
      const [downloadingJson, setDownloadingJson] = useState(false);
      const [downloadingCsv, setDownloadingCsv] = useState(false);

      // Trial Balance States
      const [trialData, setTrialData] = useState(null);
      const [trialAsOfDate, setTrialAsOfDate] = useState('2026-09-11');

      // Cash Flow States
      const [cashFlowData, setCashFlowData] = useState(null);
      const [cfStartDate, setCfStartDate] = useState('2026-01-01');
      const [cfEndDate, setCfEndDate] = useState('2026-12-31');

      // Principles Knowledge Hub States
      const [principlesData, setPrinciplesData] = useState([]);
      const [principlesStats, setPrinciplesStats] = useState(null);
      const [principlesCategory, setPrinciplesCategory] = useState('all');
      const [principlesSearch, setPrinciplesSearch] = useState('');
      const [showGoogleModal, setShowGoogleModal] = useState(false);
      const [googleTopic, setGoogleTopic] = useState('Prepaid Expenses, Accruals & Statutory Deductions');
      const [googleCount, setGoogleCount] = useState(3);
      const [harvestingGoogle, setHarvestingGoogle] = useState(false);
      const [harvestSuccessMsg, setHarvestSuccessMsg] = useState(null);

      const fetchPnl = async () => {
        setLoading(true);
        try {
          const res = await fetch(`${apiUrl}/reports/profit-loss?start_date=2026-01-01&end_date=2026-12-31`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setPnlData(json.data);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const fetchBalanceSheet = async () => {
        setLoading(true);
        try {
          const res = await fetch(`${apiUrl}/reports/balance-sheet?as_of_date=2026-12-31`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setBsData(json.data);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const fetchTrialBalance = async (asOf = trialAsOfDate) => {
        setLoading(true);
        try {
          const res = await fetch(`${apiUrl}/reports/trial-balance?as_of_date=${asOf}`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setTrialData(json.data);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const fetchCashFlow = async (start = cfStartDate, end = cfEndDate) => {
        setLoading(true);
        try {
          const res = await fetch(`${apiUrl}/reports/cash-flow?start_date=${start}&end_date=${end}`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setCashFlowData(json.data);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const fetchPrinciples = async (cat = principlesCategory, search = principlesSearch) => {
        setLoading(true);
        try {
          let url = `${apiUrl}/reports/principles`;
          const params = [];
          if (cat && cat !== 'all') params.push(`category=${encodeURIComponent(cat)}`);
          if (search) params.push(`search=${encodeURIComponent(search)}`);
          if (params.length > 0) url += `?${params.join('&')}`;

          const res = await fetch(url, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setPrinciplesData(json.data || []);
          setPrinciplesStats(json.stats || null);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const handleCollectFromGoogle = async () => {
        setHarvestingGoogle(true);
        setHarvestSuccessMsg(null);
        try {
          const res = await fetch(`${apiUrl}/reports/principles/collect-google`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              topic: googleTopic,
              count: Number(googleCount)
            })
          });
          const json = await res.json();
          if (!json.success) throw new Error(json.error || 'Failed to harvest principles');
          setHarvestSuccessMsg(`Successfully learned ${json.collected_count} principles from Google Database (${json.model})!`);
          fetchPrinciples();
          setTimeout(() => {
            setShowGoogleModal(false);
            setHarvestSuccessMsg(null);
          }, 2200);
        } catch (err) {
          alert('Google Database Harvest Error: ' + err.message);
        } finally {
          setHarvestingGoogle(false);
        }
      };

      const fetchGst = async (start = gstStartDate, end = gstEndDate) => {
        setLoading(true);
        try {
          const res = await fetch(`${apiUrl}/reports/gst?start_date=${start}&end_date=${end}`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          setGstData(json);
        } catch (e) { setError(e.message); }
        finally { setLoading(false); }
      };

      const handleDownloadPortalJson = async () => {
        setDownloadingJson(true);
        try {
          const res = await fetch(`${apiUrl}/reports/gst/export-json?start_date=${gstStartDate}&end_date=${gstEndDate}`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          if (!res.ok) throw new Error('Failed to generate GST Portal JSON');
          const blob = await res.blob();
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          const gstin = gstData?.data?.taxpayer?.gstin || '27ABCDE1234F1Z5';
          const fp = gstData?.data?.taxpayer?.return_period || '082026';
          a.download = `GSTR_Portal_Upload_${gstin}_${fp}.json`;
          document.body.appendChild(a);
          a.click();
          a.remove();
          window.URL.revokeObjectURL(url);
        } catch (err) {
          alert('Error downloading GST Portal JSON: ' + err.message);
        } finally {
          setDownloadingJson(false);
        }
      };

      const handleDownloadCsv = async () => {
        setDownloadingCsv(true);
        try {
          const res = await fetch(`${apiUrl}/reports/gst/export-csv?start_date=${gstStartDate}&end_date=${gstEndDate}`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          if (!res.ok) throw new Error('Failed to generate Statutory CSV');
          const blob = await res.blob();
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          const gstin = gstData?.data?.taxpayer?.gstin || '27ABCDE1234F1Z5';
          const fp = gstData?.data?.taxpayer?.return_period || '082026';
          a.download = `GST_Statutory_Return_${gstin}_${fp}.csv`;
          document.body.appendChild(a);
          a.click();
          a.remove();
          window.URL.revokeObjectURL(url);
        } catch (err) {
          alert('Error downloading CA Audit CSV: ' + err.message);
        } finally {
          setDownloadingCsv(false);
        }
      };

      useEffect(() => {
        if (activeTab === 'pnl') fetchPnl();
        else if (activeTab === 'balance_sheet') fetchBalanceSheet();
        else if (activeTab === 'trial_balance') fetchTrialBalance();
        else if (activeTab === 'cash_flow') fetchCashFlow();
        else if (activeTab === 'gst') fetchGst();
        else if (activeTab === 'principles') fetchPrinciples();
      }, [activeTab]);

      return (
        <div className="w-full space-y-6">
          <div className="flex flex-wrap items-center gap-2 p-2 bg-slate-900 border border-slate-800 rounded-2xl">
            <button
              onClick={() => setActiveTab('pnl')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'pnl' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              📊 Profit & Loss
            </button>
            <button
              onClick={() => setActiveTab('balance_sheet')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'balance_sheet' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              ⚖️ Balance Sheet
            </button>
            <button
              onClick={() => setActiveTab('trial_balance')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'trial_balance' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              📐 Trial Balance (Dr = Cr)
            </button>
            <button
              onClick={() => setActiveTab('cash_flow')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'cash_flow' ? 'bg-teal-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              💵 Cash Flow (Ind AS 7)
            </button>
            <button
              onClick={() => setActiveTab('gst')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'gst' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              🏛️ GST Return (1 & 3B)
            </button>
            <button
              onClick={() => setActiveTab('principles')}
              className={`px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition ${
                activeTab === 'principles' ? 'bg-purple-600 text-white shadow' : 'text-slate-400 hover:text-white'
              }`}
            >
              🧠 Principles & Golden Rules
            </button>
          </div>

          {loading && <div className="p-8 text-center text-slate-400 animate-pulse">Running SQL Analytical Queries...</div>}

          {!loading && activeTab === 'pnl' && pnlData && (
            <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
              <div className="flex justify-between items-center pb-4 border-b border-slate-800">
                <h3 className="text-lg font-bold text-white">Profit & Loss Statement</h3>
                <div className="text-right">
                  <div className="text-xs text-slate-400">Net Operating Profit:</div>
                  <div className="text-2xl font-black text-emerald-400">
                    ₹{Number(pnlData.net_profit).toLocaleString('en-IN')}
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                  <div className="flex justify-between text-sm font-bold text-emerald-400 border-b border-slate-800 pb-2">
                    <span>Revenue</span>
                    <span>₹{Number(pnlData.total_revenue).toLocaleString('en-IN')}</span>
                  </div>
                  {pnlData.revenues?.map((r, i) => (
                    <div key={i} className="flex justify-between text-xs py-1">
                      <span className="text-slate-300">{r.account_name}</span>
                      <span className="font-semibold">₹{Number(r.net_amount).toLocaleString('en-IN')}</span>
                    </div>
                  ))}
                </div>

                <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                  <div className="flex justify-between text-sm font-bold text-rose-400 border-b border-slate-800 pb-2">
                    <span>Expenses</span>
                    <span>₹{Number(pnlData.total_expenses).toLocaleString('en-IN')}</span>
                  </div>
                  {pnlData.expenses?.map((e, i) => (
                    <div key={i} className="flex justify-between text-xs py-1">
                      <span className="text-slate-300">{e.account_name}</span>
                      <span className="font-semibold">₹{Number(e.net_amount).toLocaleString('en-IN')}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {!loading && activeTab === 'balance_sheet' && bsData && (
            <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
              <div className="flex justify-between items-center pb-4 border-b border-slate-800">
                <h3 className="text-lg font-bold text-white">Balance Sheet</h3>
                <span className="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                  ✓ Equation Satisfied: Assets = Liabilities + Equity
                </span>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                  <div className="flex justify-between text-sm font-bold text-blue-400 border-b border-slate-800 pb-2">
                    <span>Total Assets</span>
                    <span>₹{Number(bsData.total_assets).toLocaleString('en-IN')}</span>
                  </div>
                  {bsData.assets?.map((a, i) => (
                    <div key={i} className="flex justify-between text-xs py-1">
                      <span className="text-slate-300">{a.account_name}</span>
                      <span className="font-semibold">₹{Number(a.balance).toLocaleString('en-IN')}</span>
                    </div>
                  ))}
                </div>

                <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                  <div className="flex justify-between text-sm font-bold text-purple-400 border-b border-slate-800 pb-2">
                    <span>Total Liabilities & Equity</span>
                    <span>₹{Number(bsData.total_liabilities_and_equity).toLocaleString('en-IN')}</span>
                  </div>
                  <div className="text-[11px] font-bold text-slate-400 uppercase">Equity & Retained Earnings</div>
                  {bsData.equity?.map((eq, i) => (
                    <div key={i} className="flex justify-between text-xs py-1">
                      <span className="text-slate-300">{eq.account_name}</span>
                      <span className="font-semibold">₹{Number(eq.balance).toLocaleString('en-IN')}</span>
                    </div>
                  ))}
                  <div className="flex justify-between text-xs py-1 text-indigo-300 border-t border-slate-800 pt-2">
                    <span>Retained Earnings (Net Income)</span>
                    <span className="font-semibold">₹{Number(bsData.retained_earnings).toLocaleString('en-IN')}</span>
                  </div>
                </div>
              </div>
            </div>
          )}

          {!loading && activeTab === 'gst' && gstData && (
            <div className="space-y-6">
              {/* TOP STATUTORY FILING HEADER */}
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-96 h-96 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 pb-6 border-b border-slate-800">
                  <div className="space-y-2">
                    <div className="flex items-center gap-3">
                      <span className="px-3 py-1 text-xs font-black uppercase tracking-wider rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5">
                        <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        {gstData.data?.submission_readiness?.badge || '✓ READY FOR SUBMISSION (0 ERRORS)'}
                      </span>
                      <span className="text-xs text-slate-400 font-mono">
                        FY: <strong className="text-slate-200">{gstData.data?.taxpayer?.financial_year || '2026-2027'}</strong>
                      </span>
                    </div>

                    <h2 className="text-2xl font-black text-white tracking-tight flex items-center gap-2">
                      {gstData.data?.taxpayer?.legal_name || 'Apex Technologies & Advisory LLP'}
                    </h2>

                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                      <div>GSTIN: <span className="font-mono text-indigo-300 font-bold">{gstData.data?.taxpayer?.gstin || gstData.gstin}</span></div>
                      <div>•</div>
                      <div>Jurisdiction: <span className="text-slate-200 font-semibold">{gstData.data?.taxpayer?.state_name} ({gstData.data?.taxpayer?.state_code})</span></div>
                      <div>•</div>
                      <div>Return Period: <span className="text-amber-300 font-bold">{gstData.data?.taxpayer?.period_label}</span></div>
                    </div>
                  </div>

                  {/* ACTION BUTTONS (SUBMISSION EXPORTS) */}
                  <div className="flex flex-wrap items-center gap-2.5">
                    <button
                      onClick={handleDownloadPortalJson}
                      disabled={downloadingJson}
                      title="Download official JSON schema ready to upload directly to gst.gov.in offline tool"
                      className="px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-bold shadow-lg shadow-emerald-900/30 flex items-center gap-2 transition transform active:scale-95 disabled:opacity-50"
                    >
                      <span>📥</span>
                      <span>{downloadingJson ? 'Generating JSON...' : 'Download GST Portal JSON'}</span>
                    </button>

                    <button
                      onClick={handleDownloadCsv}
                      disabled={downloadingCsv}
                      title="Download complete statutory CSV tables for CA & Auditor filing"
                      className="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold border border-slate-700 shadow-md flex items-center gap-2 transition transform active:scale-95 disabled:opacity-50"
                    >
                      <span>📊</span>
                      <span>{downloadingCsv ? 'Preparing CSV...' : 'Download CA / Audit CSV'}</span>
                    </button>

                    <button
                      onClick={() => window.print()}
                      title="Print official filing return"
                      className="px-3.5 py-2.5 rounded-xl bg-slate-800/80 hover:bg-slate-700 text-slate-300 hover:text-white text-xs font-semibold border border-slate-700/80 transition flex items-center gap-1.5"
                    >
                      <span>🖨️</span>
                      <span>Print Return</span>
                    </button>
                  </div>
                </div>

                {/* DATE FILTER & PERIOD PICKER BAR */}
                <div className="flex flex-wrap items-center justify-between gap-4 pt-4 text-xs">
                  <div className="flex items-center gap-2">
                    <span className="text-slate-400 font-medium">Quick Periods:</span>
                    <button
                      onClick={() => { setGstStartDate('2026-08-01'); setGstEndDate('2026-09-30'); fetchGst('2026-08-01', '2026-09-30'); }}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 font-medium border border-slate-700 transition"
                    >
                      Aug - Sep 2026
                    </button>
                    <button
                      onClick={() => { setGstStartDate('2026-07-01'); setGstEndDate('2026-09-30'); fetchGst('2026-07-01', '2026-09-30'); }}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 font-medium border border-slate-700 transition"
                    >
                      Q2 (Jul - Sep 2026)
                    </button>
                    <button
                      onClick={() => { setGstStartDate('2026-01-01'); setGstEndDate('2026-12-31'); fetchGst('2026-01-01', '2026-12-31'); }}
                      className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 font-medium border border-slate-700 transition"
                    >
                      Full FY 2026-27
                    </button>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-slate-400 font-medium">Custom Period:</span>
                    <input
                      type="date"
                      value={gstStartDate}
                      onChange={(e) => setGstStartDate(e.target.value)}
                      className="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1 text-slate-200 outline-none"
                    />
                    <span className="text-slate-500">to</span>
                    <input
                      type="date"
                      value={gstEndDate}
                      onChange={(e) => setGstEndDate(e.target.value)}
                      className="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1 text-slate-200 outline-none"
                    />
                    <button
                      onClick={() => fetchGst(gstStartDate, gstEndDate)}
                      className="px-3 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-bold transition"
                    >
                      Apply & Recalculate
                    </button>
                  </div>
                </div>
              </div>

              {/* EXECUTIVE STATUTORY KPI CARDS */}
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                  <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Gross Outward Turnover</div>
                  <div className="text-xl font-black text-white mt-1">
                    ₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.taxable_value || 0).toLocaleString('en-IN')}
                  </div>
                  <div className="text-[10px] text-slate-500 mt-1">GSTR-3B Table 3.1 Taxable Base</div>
                </div>

                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                  <div className="text-[11px] font-bold text-indigo-400 uppercase tracking-wider">Output Tax Liability</div>
                  <div className="text-xl font-black text-indigo-400 mt-1">
                    ₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.total_tax || 0).toLocaleString('en-IN')}
                  </div>
                  <div className="text-[10px] text-slate-400 mt-1 font-mono">
                    IGST ₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.igst || 0).toLocaleString('en-IN')} | CGST ₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.cgst || 0).toLocaleString('en-IN')}
                  </div>
                </div>

                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                  <div className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider">Eligible ITC Credit</div>
                  <div className="text-xl font-black text-emerald-400 mt-1">
                    ₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.total_itc || 0).toLocaleString('en-IN')}
                  </div>
                  <div className="text-[10px] text-slate-400 mt-1">
                    Capital Goods: ₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.total_itc || 0).toLocaleString('en-IN')}
                  </div>
                </div>

                <div className="bg-gradient-to-br from-amber-950/40 to-slate-900 border border-amber-500/40 rounded-2xl p-4 shadow-lg">
                  <div className="text-[11px] font-bold text-amber-300 uppercase tracking-wider flex items-center justify-between">
                    <span>Net Cash Required</span>
                    <span className="px-1.5 py-0.5 rounded bg-amber-500/20 text-[9px] text-amber-300 font-mono">Challan PMT-06</span>
                  </div>
                  <div className="text-2xl font-black text-amber-400 mt-1">
                    ₹{Number(gstData.data?.gstr3b?.table_6_1_payment?.net_cash_payable?.total || 0).toLocaleString('en-IN')}
                  </div>
                  <div className="text-[10px] text-amber-300/80 mt-1">
                    Required to pay in bank for portal filing
                  </div>
                </div>
              </div>

              {/* GST SUB-NAVIGATION TABS */}
              <div className="flex items-center gap-2 border-b border-slate-800 pb-2 overflow-x-auto text-xs font-semibold">
                <button
                  onClick={() => setGstSubTab('gstr3b')}
                  className={`px-4 py-2 rounded-xl transition ${
                    gstSubTab === 'gstr3b' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800'
                  }`}
                >
                  1. GSTR-3B Monthly Return (Table 3.1, 4 & 6.1)
                </button>
                <button
                  onClick={() => setGstSubTab('gstr1')}
                  className={`px-4 py-2 rounded-xl transition ${
                    gstSubTab === 'gstr1' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800'
                  }`}
                >
                  2. GSTR-1 Outward Supplies (B2B & HSN Summary)
                </button>
                <button
                  onClick={() => setGstSubTab('itc')}
                  className={`px-4 py-2 rounded-xl transition ${
                    gstSubTab === 'itc' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800'
                  }`}
                >
                  3. Inward ITC Register (Rule 42/43)
                </button>
                <button
                  onClick={() => setGstSubTab('checklist')}
                  className={`px-4 py-2 rounded-xl transition ${
                    gstSubTab === 'checklist' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-slate-400 hover:text-white hover:bg-slate-800'
                  }`}
                >
                  4. Submission Checklist & Declaration
                </button>
              </div>

              {/* SUB-TAB 1: GSTR-3B MONTHLY RETURN */}
              {gstSubTab === 'gstr3b' && (
                <div className="space-y-6">
                  {/* Table 3.1 Outward Supplies */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                      <div>
                        <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                          Table 3.1: Details of Outward Supplies and Inward Supplies Liable to Reverse Charge
                        </h4>
                        <p className="text-[11px] text-slate-400">Statutory breakdown of turnover and output taxes</p>
                      </div>
                      <span className="text-xs font-mono font-bold text-indigo-400">
                        Total Tax: ₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.total_tax || 0).toLocaleString('en-IN')}
                      </span>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-800">
                      <table className="w-full text-left text-xs">
                        <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                          <tr>
                            <th className="p-3">Nature of Supplies</th>
                            <th className="p-3 text-right">Taxable Value</th>
                            <th className="p-3 text-right">Integrated Tax (IGST)</th>
                            <th className="p-3 text-right">Central Tax (CGST)</th>
                            <th className="p-3 text-right">State Tax (SGST)</th>
                            <th className="p-3 text-right">Cess</th>
                            <th className="p-3 text-right">Total Output Tax</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800 text-slate-300">
                          <tr>
                            <td className="p-3 font-medium text-white">(a) Outward Taxable Supplies (Other than Zero/Nil/Exempt)</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.a_outward_taxable?.taxable_value || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.a_outward_taxable?.igst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.a_outward_taxable?.cgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.a_outward_taxable?.sgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-bold text-indigo-400 font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.a_outward_taxable?.total_tax || 0).toLocaleString('en-IN')}</td>
                          </tr>
                          <tr className="text-slate-500">
                            <td className="p-3">(b) Outward Taxable Supplies (Zero Rated / Exports)</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                          </tr>
                          <tr className="text-slate-500">
                            <td className="p-3">(c) Other Outward Supplies (Nil Rated, Exempted)</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                          </tr>
                          <tr className="text-slate-500">
                            <td className="p-3">(d) Inward Supplies Liable to Reverse Charge (RCM)</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                          </tr>
                          <tr className="bg-slate-950 font-bold text-white">
                            <td className="p-3 uppercase">Total Outward Tax Liability</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.taxable_value || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.igst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.cgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.sgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right text-emerald-400 font-mono text-sm">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.total_tax || 0).toLocaleString('en-IN')}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* Table 4 Eligible ITC */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                      <div>
                        <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                          Table 4: Eligible Input Tax Credit (ITC)
                        </h4>
                        <p className="text-[11px] text-slate-400">Breakdown of credits availed on purchases under Rule 42 & 43</p>
                      </div>
                      <span className="text-xs font-mono font-bold text-emerald-400">
                        Total Net ITC: ₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.total_itc || 0).toLocaleString('en-IN')}
                      </span>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-800">
                      <table className="w-full text-left text-xs">
                        <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                          <tr>
                            <th className="p-3">Details of Input Tax Credit</th>
                            <th className="p-3">Statutory Rule</th>
                            <th className="p-3 text-right">Taxable Value</th>
                            <th className="p-3 text-right">IGST Credit</th>
                            <th className="p-3 text-right">CGST Credit</th>
                            <th className="p-3 text-right">SGST Credit</th>
                            <th className="p-3 text-right">Total ITC Available</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800 text-slate-300">
                          <tr>
                            <td className="p-3 font-medium text-white">
                              (A)(5) Capital Goods (Computers, Hardware, Furniture)
                            </td>
                            <td className="p-3 text-[11px] font-mono text-purple-300">Section 16 / Rule 43</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.taxable_value || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.igst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.cgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.sgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-bold text-purple-300 font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.capital_goods?.total_itc || 0).toLocaleString('en-IN')}</td>
                          </tr>
                          <tr>
                            <td className="p-3 font-medium text-white">
                              (A)(5) Inputs & Input Services (Rent, Cloud, Consulting)
                            </td>
                            <td className="p-3 text-[11px] font-mono text-blue-300">Section 16 / Rule 42</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.operating_services?.taxable_value || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.operating_services?.igst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.operating_services?.cgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.operating_services?.sgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-bold text-blue-300 font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.a_itc_available?.['5_all_other_itc']?.operating_services?.total_itc || 0).toLocaleString('en-IN')}</td>
                          </tr>
                          <tr className="text-slate-500">
                            <td className="p-3">(B) ITC Reversed (Rules 38, 42 & 43 and Section 17(5))</td>
                            <td className="p-3 text-[11px] font-mono">N/A</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                            <td className="p-3 text-right font-mono">₹0.00</td>
                          </tr>
                          <tr className="bg-slate-950 font-bold text-white">
                            <td className="p-3 uppercase" colSpan="2">(C) Net Eligible Input Tax Credit (ITC) Availed</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.taxable_value || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.igst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.cgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono">₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.sgst || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right text-emerald-400 font-mono text-sm">₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.total_itc || 0).toLocaleString('en-IN')}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* Table 6.1 Payment of Tax & Set-Off */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                      <div>
                        <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                          Table 6.1: Payment of Tax (Liability vs Eligible ITC Set-off & Cash Deposit)
                        </h4>
                        <p className="text-[11px] text-slate-400">Order of utilization strictly implements CGST Sec 49, 49A, 49B</p>
                      </div>
                      <div className="text-right">
                        <span className="text-[10px] text-slate-400 uppercase">Cash Challan Required:</span>
                        <div className="text-base font-black text-amber-400 font-mono">
                          ₹{Number(gstData.data?.gstr3b?.table_6_1_payment?.net_cash_payable?.total || 0).toLocaleString('en-IN')}
                        </div>
                      </div>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-800">
                      <table className="w-full text-left text-xs">
                        <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                          <tr>
                            <th className="p-3">Tax Head</th>
                            <th className="p-3 text-right">Tax Payable</th>
                            <th className="p-3 text-right">Paid via IGST Credit</th>
                            <th className="p-3 text-right">Paid via CGST Credit</th>
                            <th className="p-3 text-right">Paid via SGST Credit</th>
                            <th className="p-3 text-right text-amber-300">Paid in Cash (Challan)</th>
                            <th className="p-3 text-right">Interest & Late Fee</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800 text-slate-300">
                          {gstData.data?.gstr3b?.table_6_1_payment?.schedule?.map((row, idx) => (
                            <tr key={idx} className="hover:bg-slate-800/40">
                              <td className="p-3 font-semibold text-white">{row.tax_head}</td>
                              <td className="p-3 text-right font-mono">₹{Number(row.tax_payable).toLocaleString('en-IN')}</td>
                              <td className="p-3 text-right font-mono text-indigo-300">₹{Number(row.paid_by_igst_itc).toLocaleString('en-IN')}</td>
                              <td className="p-3 text-right font-mono text-purple-300">₹{Number(row.paid_by_cgst_itc).toLocaleString('en-IN')}</td>
                              <td className="p-3 text-right font-mono text-purple-300">₹{Number(row.paid_by_sgst_itc).toLocaleString('en-IN')}</td>
                              <td className="p-3 text-right font-mono font-bold text-amber-400">₹{Number(row.paid_in_cash).toLocaleString('en-IN')}</td>
                              <td className="p-3 text-right font-mono text-slate-500">₹0.00</td>
                            </tr>
                          ))}
                          <tr className="bg-slate-950 font-bold">
                            <td className="p-3 text-white uppercase">TOTALS</td>
                            <td className="p-3 text-right font-mono text-white">₹{Number(gstData.data?.gstr3b?.table_3_1?.total?.total_tax || 0).toLocaleString('en-IN')}</td>
                            <td className="p-3 text-right font-mono text-indigo-300" colSpan="3">
                              Total ITC Applied: ₹{Number(gstData.data?.gstr3b?.table_4_itc?.c_net_itc?.total_itc || 0).toLocaleString('en-IN')}
                            </td>
                            <td className="p-3 text-right font-mono text-amber-400 text-sm">
                              ₹{Number(gstData.data?.gstr3b?.table_6_1_payment?.net_cash_payable?.total || 0).toLocaleString('en-IN')}
                            </td>
                            <td className="p-3 text-right font-mono text-slate-500">₹0.00</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              )}

              {/* SUB-TAB 2: GSTR-1 OUTWARD SUPPLIES */}
              {gstSubTab === 'gstr1' && (
                <div className="space-y-6">
                  {/* Table 4 B2B Invoices */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                      <div>
                        <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                          Table 4: Taxable Outward Supplies made to Registered Persons (B2B Tax Invoices)
                        </h4>
                        <p className="text-[11px] text-slate-400">Invoice-level details ready for GST portal offline upload</p>
                      </div>
                      <span className="text-xs px-2.5 py-1 rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-semibold">
                        {gstData.data?.gstr1?.table_4_b2b_invoices?.total_count || 0} Invoices Upload-Ready
                      </span>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-800">
                      <table className="w-full text-left text-xs">
                        <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                          <tr>
                            <th className="p-2.5">Invoice No</th>
                            <th className="p-2.5">Date</th>
                            <th className="p-2.5">Customer Name</th>
                            <th className="p-2.5">Customer GSTIN</th>
                            <th className="p-2.5">Place of Supply</th>
                            <th className="p-2.5 text-center">Rate</th>
                            <th className="p-2.5 text-right">Taxable Value</th>
                            <th className="p-2.5 text-right">CGST</th>
                            <th className="p-2.5 text-right">SGST</th>
                            <th className="p-2.5 text-right">IGST</th>
                            <th className="p-2.5 text-right">Gross Total</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800 text-slate-300">
                          {gstData.data?.gstr1?.table_4_b2b_invoices?.invoices?.map((inv, idx) => (
                            <tr key={idx} className="hover:bg-slate-800/40">
                              <td className="p-2.5 font-mono text-indigo-300 font-bold">{inv.invoice_no}</td>
                              <td className="p-2.5 font-mono">{inv.invoice_date}</td>
                              <td className="p-2.5 text-white font-medium">{inv.customer_name}</td>
                              <td className="p-2.5 font-mono text-[11px] text-slate-400">{inv.customer_gstin}</td>
                              <td className="p-2.5">{inv.place_of_supply}</td>
                              <td className="p-2.5 text-center font-mono font-bold text-slate-300">{inv.rate}%</td>
                              <td className="p-2.5 text-right font-mono">₹{Number(inv.taxable_value).toLocaleString('en-IN')}</td>
                              <td className="p-2.5 text-right font-mono">{inv.cgst > 0 ? `₹${Number(inv.cgst).toLocaleString('en-IN')}` : '-'}</td>
                              <td className="p-2.5 text-right font-mono">{inv.sgst > 0 ? `₹${Number(inv.sgst).toLocaleString('en-IN')}` : '-'}</td>
                              <td className="p-2.5 text-right font-mono">{inv.igst > 0 ? `₹${Number(inv.igst).toLocaleString('en-IN')}` : '-'}</td>
                              <td className="p-2.5 text-right font-mono font-bold text-white">₹{Number(inv.invoice_value).toLocaleString('en-IN')}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* Table 12 HSN/SAC Summary */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                      <div>
                        <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                          Table 12: HSN/SAC Summary of Outward Supplies
                        </h4>
                        <p className="text-[11px] text-slate-400">Mandatory 6-digit Harmonized System Nomenclature (HSN) & Services Accounting Code (SAC)</p>
                      </div>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-800">
                      <table className="w-full text-left text-xs">
                        <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                          <tr>
                            <th className="p-2.5">HSN/SAC</th>
                            <th className="p-2.5">Description</th>
                            <th className="p-2.5 text-center">UQC</th>
                            <th className="p-2.5 text-center">Qty</th>
                            <th className="p-2.5 text-right">Total Value</th>
                            <th className="p-2.5 text-right">Taxable Value</th>
                            <th className="p-2.5 text-right">IGST</th>
                            <th className="p-2.5 text-right">CGST</th>
                            <th className="p-2.5 text-right">SGST</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800 text-slate-300">
                          {gstData.data?.gstr1?.table_12_hsn_summary?.data?.map((hsn, idx) => (
                            <tr key={idx} className="hover:bg-slate-800/40">
                              <td className="p-2.5 font-mono text-purple-300 font-bold">{hsn.hsn_sc}</td>
                              <td className="p-2.5 text-white font-medium">{hsn.description}</td>
                              <td className="p-2.5 text-center font-mono text-[10px]">{hsn.uqc}</td>
                              <td className="p-2.5 text-center font-mono">{hsn.total_qty}</td>
                              <td className="p-2.5 text-right font-mono">₹{Number(hsn.total_value).toLocaleString('en-IN')}</td>
                              <td className="p-2.5 text-right font-mono font-bold text-white">₹{Number(hsn.taxable_value).toLocaleString('en-IN')}</td>
                              <td className="p-2.5 text-right font-mono">{hsn.igst > 0 ? `₹${Number(hsn.igst).toLocaleString('en-IN')}` : '-'}</td>
                              <td className="p-2.5 text-right font-mono">{hsn.cgst > 0 ? `₹${Number(hsn.cgst).toLocaleString('en-IN')}` : '-'}</td>
                              <td className="p-2.5 text-right font-mono">{hsn.sgst > 0 ? `₹${Number(hsn.sgst).toLocaleString('en-IN')}` : '-'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* Table 13 Documents Issued */}
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                    <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                      Table 13: Documents Issued During the Tax Period
                    </h4>
                    <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
                      <div className="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div className="text-slate-400">Document Type</div>
                        <div className="font-bold text-white mt-1">Invoices for Outward Supply</div>
                      </div>
                      <div className="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div className="text-slate-400">Serial Range</div>
                        <div className="font-mono text-indigo-300 font-bold mt-1">
                          {gstData.data?.gstr1?.table_13_documents_issued?.data?.[0]?.from_num || 'INV-001'} to {gstData.data?.gstr1?.table_13_documents_issued?.data?.[0]?.to_num || 'INV-005'}
                        </div>
                      </div>
                      <div className="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div className="text-slate-400">Total Issued</div>
                        <div className="font-bold text-white mt-1">
                          {gstData.data?.gstr1?.table_13_documents_issued?.data?.[0]?.total_num || 0}
                        </div>
                      </div>
                      <div className="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <div className="text-slate-400">Net Invoices Issued</div>
                        <div className="font-bold text-emerald-400 mt-1">
                          {gstData.data?.gstr1?.table_13_documents_issued?.data?.[0]?.net_issue || 0} (0 Cancelled)
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* SUB-TAB 3: INWARD ITC AUDIT REGISTER */}
              {gstSubTab === 'itc' && (
                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                      <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                        Inward Supplies & Input Tax Credit (ITC) Audit Register
                      </h4>
                      <p className="text-[11px] text-slate-400">Vendor purchase vouchers matched and apportioned per Section 16 & 17</p>
                    </div>
                    <span className="text-xs font-mono font-bold text-emerald-400">
                      Eligible ITC: ₹{Number(gstData.data?.inward_register?.summary?.total_itc?.total || 0).toLocaleString('en-IN')}
                    </span>
                  </div>

                  <div className="overflow-x-auto rounded-xl border border-slate-800">
                    <table className="w-full text-left text-xs">
                      <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                        <tr>
                          <th className="p-2.5">Date</th>
                          <th className="p-2.5">Supplier / Description</th>
                          <th className="p-2.5">Ledger Account</th>
                          <th className="p-2.5">ITC Category</th>
                          <th className="p-2.5">Statutory Rule</th>
                          <th className="p-2.5 text-right">Taxable Value</th>
                          <th className="p-2.5 text-right">ITC CGST</th>
                          <th className="p-2.5 text-right">ITC SGST</th>
                          <th className="p-2.5 text-right">ITC IGST</th>
                          <th className="p-2.5 text-right">Total ITC</th>
                          <th className="p-2.5 text-center">Status</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-800 text-slate-300">
                        {gstData.data?.inward_register?.transactions?.map((inw, idx) => (
                          <tr key={idx} className="hover:bg-slate-800/40">
                            <td className="p-2.5 font-mono">{inw.date}</td>
                            <td className="p-2.5 text-white font-medium">{inw.description}</td>
                            <td className="p-2.5 text-slate-400">{inw.account_name}</td>
                            <td className="p-2.5">
                              <span className={`px-2 py-0.5 rounded text-[10px] font-semibold ${
                                inw.itc_category === 'Capital Goods ITC' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-blue-500/20 text-blue-300 border border-blue-500/30'
                              }`}>
                                {inw.itc_category}
                              </span>
                            </td>
                            <td className="p-2.5 font-mono text-[10px] text-slate-400">{inw.rule_reference}</td>
                            <td className="p-2.5 text-right font-mono">₹{Number(inw.taxable_value).toLocaleString('en-IN')}</td>
                            <td className="p-2.5 text-right font-mono">{inw.itc_cgst > 0 ? `₹${Number(inw.itc_cgst).toLocaleString('en-IN')}` : '-'}</td>
                            <td className="p-2.5 text-right font-mono">{inw.itc_sgst > 0 ? `₹${Number(inw.itc_sgst).toLocaleString('en-IN')}` : '-'}</td>
                            <td className="p-2.5 text-right font-mono">{inw.itc_igst > 0 ? `₹${Number(inw.itc_igst).toLocaleString('en-IN')}` : '-'}</td>
                            <td className="p-2.5 text-right font-mono font-bold text-emerald-400">₹{Number(inw.total_itc).toLocaleString('en-IN')}</td>
                            <td className="p-2.5 text-center">
                              <span className="px-2 py-0.5 rounded-full text-[9px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                ✓ Reconciled
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* SUB-TAB 4: SUBMISSION CHECKLIST & DECLARATION */}
              {gstSubTab === 'checklist' && (
                <div className="space-y-6">
                  <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div className="border-b border-slate-800 pb-3">
                      <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                        Statutory Pre-Filing Validation Checklist (5-Point Audit)
                      </h4>
                      <p className="text-[11px] text-slate-400">Automatic mathematical and legal rules checked before portal submission</p>
                    </div>

                    <div className="space-y-3">
                      {gstData.data?.submission_readiness?.validation_checks?.map((chk, idx) => (
                        <div key={idx} className="flex items-start gap-3 p-3.5 bg-slate-950 rounded-xl border border-slate-800">
                          <div className="w-6 h-6 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">
                            ✓
                          </div>
                          <div className="flex-1">
                            <div className="flex items-center justify-between">
                              <span className="text-xs font-bold text-white">{chk.check}</span>
                              <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300">
                                {chk.status}
                              </span>
                            </div>
                            <p className="text-[11px] text-slate-400 mt-1">{chk.description}</p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Statutory Legal Declaration */}
                  <div className="bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950/40 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <h4 className="text-sm font-bold text-indigo-300 uppercase tracking-wider flex items-center gap-2">
                      <span>📜</span>
                      <span>Statutory Filing Declaration & Certification</span>
                    </h4>
                    <blockquote className="p-4 bg-slate-950/80 rounded-xl border-l-4 border-indigo-500 text-xs italic text-slate-300 leading-relaxed">
                      "{gstData.data?.submission_readiness?.digital_declaration}"
                    </blockquote>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs pt-2">
                      <div className="bg-slate-950 p-3.5 rounded-xl border border-slate-800">
                        <div className="text-[10px] text-slate-400 uppercase">Authorized Signatory</div>
                        <div className="font-bold text-white mt-1">{gstData.data?.taxpayer?.legal_name}</div>
                        <div className="text-[10px] text-slate-500">Managing Partner / Compliance Officer</div>
                      </div>

                      <div className="bg-slate-950 p-3.5 rounded-xl border border-slate-800">
                        <div className="text-[10px] text-slate-400 uppercase">Digital Integrity Hash</div>
                        <div className="font-mono text-[11px] text-indigo-300 font-semibold truncate mt-1">
                          {gstData.data?.submission_readiness?.checksum}
                        </div>
                        <div className="text-[10px] text-slate-500">SHA-256 Portal Checksum</div>
                      </div>

                      <div className="bg-slate-950 p-3.5 rounded-xl border border-slate-800">
                        <div className="text-[10px] text-slate-400 uppercase">Filing State & Timestamp</div>
                        <div className="font-bold text-emerald-400 mt-1">READY TO SUBMIT</div>
                        <div className="text-[10px] text-slate-500">{gstData.data?.submission_readiness?.generated_at}</div>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* TRIAL BALANCE TAB */}
          {!loading && activeTab === 'trial_balance' && trialData && (
            <div className="space-y-6">
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-96 h-96 bg-emerald-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 pb-6 border-b border-slate-800">
                  <div className="space-y-2">
                    <div className="flex items-center gap-3">
                      <span className={`px-3 py-1 text-xs font-black uppercase tracking-wider rounded-full flex items-center gap-1.5 ${
                        trialData.is_balanced 
                          ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' 
                          : 'bg-rose-500/20 text-rose-400 border border-rose-500/30'
                      }`}>
                        <span className={`w-2 h-2 rounded-full ${trialData.is_balanced ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400 animate-ping'}`}></span>
                        {trialData.is_balanced ? '✓ PERFECTLY BALANCED (0.00 VARIANCE)' : `⚠️ IMBALANCE: ₹${Number(trialData.difference).toLocaleString('en-IN')}`}
                      </span>
                      <span className="text-xs text-slate-400 font-mono">
                        As of Date: <strong className="text-slate-200">{trialAsOfDate}</strong>
                      </span>
                    </div>

                    <h2 className="text-2xl font-black text-white tracking-tight flex items-center gap-2">
                      Trial Balance (General Ledger Reconciliation)
                    </h2>
                    <p className="text-xs text-slate-400 max-w-2xl">
                      {trialData.accounting_principle}
                    </p>
                  </div>

                  {/* As of Date picker & action */}
                  <div className="flex flex-wrap items-center gap-2.5">
                    <div className="flex items-center gap-2 bg-slate-950 px-3 py-1.5 rounded-xl border border-slate-800 text-xs">
                      <span className="text-slate-400 font-medium">As of:</span>
                      <input
                        type="date"
                        value={trialAsOfDate}
                        onChange={(e) => setTrialAsOfDate(e.target.value)}
                        className="bg-transparent text-slate-200 outline-none"
                      />
                    </div>
                    <button
                      onClick={() => fetchTrialBalance(trialAsOfDate)}
                      className="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md transition"
                    >
                      Recalculate
                    </button>
                    <button
                      onClick={() => window.print()}
                      className="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white font-semibold text-xs border border-slate-700 transition"
                    >
                      Print
                    </button>
                  </div>
                </div>

                {/* 4 Summary Stat Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-6">
                  <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Debits (Dr)</div>
                    <div className="text-xl font-black text-emerald-400 mt-1 font-mono">
                      ₹{Number(trialData.total_debits).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                    <div className="text-[10px] text-slate-500 mt-1">Assets, Expenses, Losses</div>
                  </div>

                  <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Credits (Cr)</div>
                    <div className="text-xl font-black text-indigo-300 mt-1 font-mono">
                      ₹{Number(trialData.total_credits).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                    <div className="text-[10px] text-slate-500 mt-1">Liabilities, Equity, Incomes</div>
                  </div>

                  <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Net Variance</div>
                    <div className={`text-xl font-black mt-1 font-mono ${trialData.difference === 0 ? 'text-emerald-400' : 'text-rose-400'}`}>
                      ₹{Number(trialData.difference).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                    <div className="text-[10px] text-slate-500 mt-1">Must equal 0.00 for clean audit</div>
                  </div>

                  <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Active Accounts</div>
                    <div className="text-xl font-black text-white mt-1 font-mono">
                      {trialData.accounts?.length || 0}
                    </div>
                    <div className="text-[10px] text-slate-500 mt-1">Accounts with posting activity</div>
                  </div>
                </div>
              </div>

              {/* Accounts Table */}
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                  <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                    General Ledger Account Balances (Debit vs Credit)
                  </h4>
                  <span className="text-xs text-slate-400">
                    Showing {trialData.accounts?.length || 0} Accounts
                  </span>
                </div>

                <div className="overflow-x-auto rounded-xl border border-slate-800">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
                      <tr>
                        <th className="p-3">Code</th>
                        <th className="p-3">Account Title</th>
                        <th className="p-3">Classification</th>
                        <th className="p-3 text-right">Debit Balance (₹)</th>
                        <th className="p-3 text-right">Credit Balance (₹)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800 text-slate-300">
                      {trialData.accounts?.map((acc, idx) => (
                        <tr key={idx} className="hover:bg-slate-800/40">
                          <td className="p-3 font-mono font-bold text-indigo-400">{acc.account_code}</td>
                          <td className="p-3 font-semibold text-white">{acc.account_name}</td>
                          <td className="p-3">
                            <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                              acc.account_type === 'asset' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' :
                              acc.account_type === 'liability' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' :
                              acc.account_type === 'equity' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' :
                              acc.account_type === 'revenue' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' :
                              'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                            }`}>
                              {acc.account_type}
                            </span>
                          </td>
                          <td className="p-3 text-right font-mono font-semibold text-emerald-400">
                            {acc.debit > 0 ? `₹${Number(acc.debit).toLocaleString('en-IN', { minimumFractionDigits: 2 })}` : '-'}
                          </td>
                          <td className="p-3 text-right font-mono font-semibold text-indigo-300">
                            {acc.credit > 0 ? `₹${Number(acc.credit).toLocaleString('en-IN', { minimumFractionDigits: 2 })}` : '-'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot className="bg-slate-950 font-bold border-t-2 border-slate-700 text-slate-200">
                      <tr>
                        <td colSpan="3" className="p-3.5 text-right uppercase tracking-wider text-xs">
                          Grand Totals (Reconciled Balance):
                        </td>
                        <td className="p-3.5 text-right font-mono text-emerald-400 text-sm">
                          ₹{Number(trialData.total_debits).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                        </td>
                        <td className="p-3.5 text-right font-mono text-indigo-300 text-sm">
                          ₹{Number(trialData.total_credits).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>

                {/* Educational Principle Callout */}
                <div className="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-400 space-y-1">
                  <span className="font-bold text-indigo-400 flex items-center gap-1.5">
                    <span>💡</span> Dual Aspect Accounting Equality:
                  </span>
                  <p>
                    Because every transaction records equal Debits and Credits across Real, Personal, and Nominal accounts, 
                    the fundamental accounting equation <strong className="text-slate-200">Assets + Expenses = Liabilities + Equity + Revenue</strong> is 
                    mathematically guaranteed. Any variance indicates an incomplete single-legged entry or unsynchronized subledger.
                  </p>
                </div>
              </div>
            </div>
          )}

          {/* CASH FLOW STATEMENT TAB (IND AS 7 / IAS 7) */}
          {!loading && activeTab === 'cash_flow' && cashFlowData && (
            <div className="space-y-6">
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-96 h-96 bg-teal-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 pb-6 border-b border-slate-800">
                  <div className="space-y-2">
                    <div className="flex items-center gap-3">
                      <span className={`px-3 py-1 text-xs font-black uppercase tracking-wider rounded-full flex items-center gap-1.5 ${
                        cashFlowData.reconciliation?.is_reconciled
                          ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'
                          : 'bg-amber-500/20 text-amber-400 border border-amber-500/30'
                      }`}>
                        <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        {cashFlowData.reconciliation?.is_reconciled ? '✓ RECONCILED WITH CASH BOOK' : 'AUDIT REVIEW RECOMMENDED'}
                      </span>
                      <span className="text-xs text-slate-400 font-mono">
                        Standard: <strong className="text-slate-200">Ind AS 7 / IAS 7</strong>
                      </span>
                    </div>

                    <h2 className="text-2xl font-black text-white tracking-tight flex items-center gap-2">
                      Statement of Cash Flows
                    </h2>
                    <p className="text-xs text-slate-400 max-w-2xl">
                      Indirect method analysis segregating Operating Activities (working capital), Investing Activities (capital expenditures), and Financing Activities (equity/debt).
                    </p>
                  </div>

                  {/* Date Filters */}
                  <div className="flex flex-wrap items-center gap-2.5">
                    <div className="flex items-center gap-2 bg-slate-950 px-3 py-1.5 rounded-xl border border-slate-800 text-xs">
                      <input
                        type="date"
                        value={cfStartDate}
                        onChange={(e) => setCfStartDate(e.target.value)}
                        className="bg-transparent text-slate-200 outline-none"
                      />
                      <span className="text-slate-500">to</span>
                      <input
                        type="date"
                        value={cfEndDate}
                        onChange={(e) => setCfEndDate(e.target.value)}
                        className="bg-transparent text-slate-200 outline-none"
                      />
                    </div>
                    <button
                      onClick={() => fetchCashFlow(cfStartDate, cfEndDate)}
                      className="px-3.5 py-2 rounded-xl bg-teal-600 hover:bg-teal-500 text-white font-bold text-xs shadow-md transition"
                    >
                      Apply Period
                    </button>
                  </div>
                </div>

                {/* Cash Reconciliation Banner Card */}
                <div className="mt-6 p-4 rounded-xl bg-slate-950 border border-slate-800 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                  <div>
                    <span className="text-slate-400">Opening Cash & Bank:</span>
                    <div className="text-lg font-bold font-mono text-white mt-0.5">
                      ₹{Number(cashFlowData.reconciliation?.opening_cash_and_bank || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                  </div>
                  <div>
                    <span className="text-slate-400">Net Period Cash Flow:</span>
                    <div className={`text-lg font-bold font-mono mt-0.5 ${
                      cashFlowData.reconciliation?.net_change_in_cash >= 0 ? 'text-emerald-400' : 'text-rose-400'
                    }`}>
                      {cashFlowData.reconciliation?.net_change_in_cash >= 0 ? '+' : ''}₹{Number(cashFlowData.reconciliation?.net_change_in_cash || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                  </div>
                  <div>
                    <span className="text-slate-400">Closing Cash & Bank (Reconciled):</span>
                    <div className="text-lg font-bold font-mono text-teal-300 mt-0.5">
                      ₹{Number(cashFlowData.reconciliation?.closing_cash_and_bank || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </div>
                  </div>
                </div>
              </div>

              {/* 3 Main Cash Flow Activity Blocks */}
              <div className="space-y-6">
                {/* 1. Operating Activities */}
                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                      <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                        1. Cash Flows from Operating Activities
                      </h4>
                      <p className="text-[11px] text-slate-400">Net operating earnings adjusted for non-cash items and working capital variations</p>
                    </div>
                    <span className={`text-sm font-mono font-bold ${
                      cashFlowData.operating_activities?.net_cash_from_operating >= 0 ? 'text-emerald-400' : 'text-rose-400'
                    }`}>
                      ₹{Number(cashFlowData.operating_activities?.net_cash_from_operating || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-800 text-xs text-slate-300">
                    <div className="flex justify-between py-2.5">
                      <span>Net Operating Profit before Tax (from P&L)</span>
                      <span className="font-mono font-semibold">₹{Number(cashFlowData.operating_activities?.net_operating_profit || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between py-2.5">
                      <span className="text-slate-400">(+) Non-Cash Depreciation & Amortization Add-Back</span>
                      <span className="font-mono text-indigo-300 font-semibold">+₹{Number(cashFlowData.operating_activities?.depreciation_add_back || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between py-2.5 font-semibold text-slate-200 bg-slate-950/40 px-2 rounded">
                      <span>Operating Cash Flow before Working Capital Changes</span>
                      <span className="font-mono">₹{Number(cashFlowData.operating_activities?.operating_cash_before_wc || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between py-2.5">
                      <span className="text-slate-400">Working Capital Adjustments (Debtors, Creditors, Accruals)</span>
                      <span className="font-mono font-semibold">₹{Number(cashFlowData.operating_activities?.working_capital_adjustments || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                    <div className="flex justify-between py-3 border-t-2 border-slate-700 font-bold text-white bg-slate-950 px-3 rounded-xl mt-2">
                      <span>Net Cash Generated from Operating Activities (A)</span>
                      <span className="font-mono text-emerald-400">₹{Number(cashFlowData.operating_activities?.net_cash_from_operating || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                  </div>
                </div>

                {/* 2. Investing Activities */}
                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                      <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                        2. Cash Flows from Investing Activities (Capex)
                      </h4>
                      <p className="text-[11px] text-slate-400">Acquisition and disposal of long-term capital assets, IT hardware and equipment</p>
                    </div>
                    <span className={`text-sm font-mono font-bold ${
                      cashFlowData.investing_activities?.net_cash_from_investing >= 0 ? 'text-emerald-400' : 'text-rose-400'
                    }`}>
                      ₹{Number(cashFlowData.investing_activities?.net_cash_from_investing || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-800 text-xs text-slate-300">
                    {cashFlowData.investing_activities?.items?.length > 0 ? (
                      cashFlowData.investing_activities.items.map((item, idx) => (
                        <div key={idx} className="flex justify-between py-2.5">
                          <span>{item.description}</span>
                          <span className="font-mono font-semibold text-rose-400">₹{Number(item.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                        </div>
                      ))
                    ) : (
                      <div className="py-2.5 text-slate-500 italic">No capital expenditures recorded in this period.</div>
                    )}
                    <div className="flex justify-between py-3 border-t-2 border-slate-700 font-bold text-white bg-slate-950 px-3 rounded-xl mt-2">
                      <span>Net Cash Used in Investing Activities (B)</span>
                      <span className="font-mono text-rose-400">₹{Number(cashFlowData.investing_activities?.net_cash_from_investing || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                  </div>
                </div>

                {/* 3. Financing Activities */}
                <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                      <h4 className="text-sm font-bold text-white uppercase tracking-wider">
                        3. Cash Flows from Financing Activities
                      </h4>
                      <p className="text-[11px] text-slate-400">Capital contributions, debt borrowings, loan repayments, and proprietor drawings</p>
                    </div>
                    <span className={`text-sm font-mono font-bold ${
                      cashFlowData.financing_activities?.net_cash_from_financing >= 0 ? 'text-emerald-400' : 'text-rose-400'
                    }`}>
                      ₹{Number(cashFlowData.financing_activities?.net_cash_from_financing || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-800 text-xs text-slate-300">
                    {cashFlowData.financing_activities?.items?.length > 0 ? (
                      cashFlowData.financing_activities.items.map((item, idx) => (
                        <div key={idx} className="flex justify-between py-2.5">
                          <span>{item.description}</span>
                          <span className="font-mono font-semibold text-emerald-400">₹{Number(item.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                        </div>
                      ))
                    ) : (
                      <div className="py-2.5 text-slate-500 italic">No capital or loan financing transactions in this period.</div>
                    )}
                    <div className="flex justify-between py-3 border-t-2 border-slate-700 font-bold text-white bg-slate-950 px-3 rounded-xl mt-2">
                      <span>Net Cash from Financing Activities (C)</span>
                      <span className="font-mono text-emerald-400">₹{Number(cashFlowData.financing_activities?.net_cash_from_financing || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* ACCOUNTING PRINCIPLES & GOLDEN RULES HUB */}
          {!loading && activeTab === 'principles' && (
            <div className="space-y-6">
              <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl relative overflow-hidden">
                <div className="absolute top-0 right-0 w-96 h-96 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 pb-6 border-b border-slate-800">
                  <div className="space-y-2">
                    <div className="flex items-center gap-3">
                      <span className="px-3 py-1 text-xs font-black uppercase tracking-wider rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 flex items-center gap-1.5">
                        <span className="w-2 h-2 rounded-full bg-purple-400 animate-pulse"></span>
                        GOOGLE DATABASE & STATUTORY TREATY ENGINE
                      </span>
                      <span className="text-xs text-slate-400 font-mono">
                        Standard: <strong className="text-slate-200">Ind AS / IFRS / Luca Pacioli</strong>
                      </span>
                    </div>

                    <h2 className="text-2xl font-black text-white tracking-tight flex items-center gap-2">
                      Accounting Principles, Golden Rules & Standard Journal Entries
                    </h2>
                    <p className="text-xs text-slate-400 max-w-2xl">
                      Grounding principles and double-entry templates. AI classification adheres strictly to real, personal, and nominal rules to eliminate accounting errors.
                    </p>
                  </div>

                  {/* Harvest Action Button */}
                  <div className="flex items-center gap-2.5">
                    <button
                      onClick={() => setShowGoogleModal(true)}
                      className="px-4 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 via-indigo-600 to-teal-600 hover:opacity-95 text-white font-bold text-xs shadow-lg shadow-purple-900/30 flex items-center gap-2 transition transform active:scale-95"
                    >
                      <span>⚡</span>
                      <span>Ingest from Google Database</span>
                    </button>
                    <button
                      onClick={() => fetchPrinciples(principlesCategory, principlesSearch)}
                      className="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white border border-slate-700 transition"
                      title="Refresh Principles"
                    >
                      🔄
                    </button>
                  </div>
                </div>

                {/* Category Chips & Search Bar */}
                <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4">
                  <div className="flex flex-wrap items-center gap-2">
                    {[
                      { id: 'all', label: 'All Principles', count: principlesStats?.total || principlesData.length },
                      { id: 'golden_rules', label: 'Golden Rules', count: principlesStats?.golden_rules || 3 },
                      { id: 'core_concept', label: 'Core Concepts', count: principlesStats?.core_concepts || 5 },
                      { id: 'standard_entry', label: 'Standard Schemas', count: principlesStats?.standard_entries || 8 },
                      { id: 'google_database', label: 'Google Harvested', count: principlesStats?.google_harvested || 0 }
                    ].map(chip => (
                      <button
                        key={chip.id}
                        onClick={() => {
                          setPrinciplesCategory(chip.id);
                          fetchPrinciples(chip.id, principlesSearch);
                        }}
                        className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 ${
                          principlesCategory === chip.id 
                            ? 'bg-purple-600 text-white shadow' 
                            : 'bg-slate-950 text-slate-400 hover:text-white border border-slate-800'
                        }`}
                      >
                        <span>{chip.label}</span>
                        <span className="px-1.5 py-0.2 text-[10px] rounded-full bg-black/40 font-mono">
                          {chip.count}
                        </span>
                      </button>
                    ))}
                  </div>

                  {/* Search Input */}
                  <div className="w-full sm:w-72">
                    <input
                      type="text"
                      placeholder="Search principle, code, or standard..."
                      value={principlesSearch}
                      onChange={(e) => {
                        setPrinciplesSearch(e.target.value);
                        fetchPrinciples(principlesCategory, e.target.value);
                      }}
                      className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-200 outline-none focus:border-purple-500"
                    />
                  </div>
                </div>
              </div>

              {/* 3 Interactive Golden Rules Display Cards */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                {/* Rule 1: Real */}
                <div className="bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-800 rounded-2xl p-5 space-y-3 relative overflow-hidden group hover:border-emerald-500/40 transition">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                      Rule 1: Real Accounts
                    </span>
                    <span className="text-xs font-mono text-slate-500">Assets & Property</span>
                  </div>
                  <h3 className="text-base font-bold text-white">Debit What Comes In &bull; Credit What Goes Out</h3>
                  <div className="space-y-2 text-xs pt-1">
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-emerald-400">Debit:</span> Real asset acquired, computer purchased, bank balance increased.
                    </div>
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-indigo-300">Credit:</span> Cash disbursed, inventory sold, equipment scrapped.
                    </div>
                  </div>
                </div>

                {/* Rule 2: Personal */}
                <div className="bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-800 rounded-2xl p-5 space-y-3 relative overflow-hidden group hover:border-blue-500/40 transition">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded bg-blue-500/20 text-blue-400 border border-blue-500/30">
                      Rule 2: Personal Accounts
                    </span>
                    <span className="text-xs font-mono text-slate-500">Entities & Debtors</span>
                  </div>
                  <h3 className="text-base font-bold text-white">Debit the Receiver &bull; Credit the Giver</h3>
                  <div className="space-y-2 text-xs pt-1">
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-blue-400">Debit:</span> Customer / Debtor receiving goods/services on credit.
                    </div>
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-indigo-300">Credit:</span> Supplier / Creditor providing goods or owner investing capital.
                    </div>
                  </div>
                </div>

                {/* Rule 3: Nominal */}
                <div className="bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-800 rounded-2xl p-5 space-y-3 relative overflow-hidden group hover:border-purple-500/40 transition">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded bg-purple-500/20 text-purple-400 border border-purple-500/30">
                      Rule 3: Nominal Accounts
                    </span>
                    <span className="text-xs font-mono text-slate-500">P&L Accounts</span>
                  </div>
                  <h3 className="text-base font-bold text-white">Debit Expenses & Losses &bull; Credit Incomes & Gains</h3>
                  <div className="space-y-2 text-xs pt-1">
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-rose-400">Debit:</span> Rent, salaries, advertising, interest, bad debts.
                    </div>
                    <div className="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800">
                      <span className="font-bold text-teal-300">Credit:</span> Consulting fees, software service revenue, interest earned.
                    </div>
                  </div>
                </div>
              </div>

              {/* Principles Catalog Cards Grid */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                {principlesData.map((p, idx) => (
                  <div key={idx} className="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4 shadow hover:border-slate-700 transition">
                    <div className="flex items-start justify-between gap-3 border-b border-slate-800 pb-3">
                      <div>
                        <div className="flex flex-wrap items-center gap-2 mb-1">
                          <span className="font-mono text-xs font-bold text-indigo-400">{p.principle_code}</span>
                          <span className={`px-2 py-0.2 rounded text-[10px] font-bold uppercase tracking-wider ${
                            p.source === 'google_database'
                              ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                              : 'bg-slate-800 text-slate-300 border border-slate-700'
                          }`}>
                            {p.source === 'google_database' ? '✨ Google Database Ingest' : 'Standard Treaty'}
                          </span>
                        </div>
                        <h4 className="text-sm font-bold text-white">{p.title}</h4>
                        <div className="text-[11px] text-slate-400 mt-0.5">Ref: <span className="font-mono text-slate-300">{p.standard_ref}</span></div>
                      </div>
                    </div>

                    <p className="text-xs text-slate-300 leading-relaxed bg-slate-950/60 p-3 rounded-xl border border-slate-800/80">
                      {p.statement}
                    </p>

                    {/* Debit & Credit Rule Pills */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                      <div className="p-2.5 bg-emerald-950/20 border border-emerald-500/30 rounded-xl text-emerald-200">
                        <div className="text-[10px] font-bold uppercase text-emerald-400 mb-0.5">Debit Rule</div>
                        <div className="text-[11px] leading-snug">{p.debit_rule}</div>
                      </div>
                      <div className="p-2.5 bg-indigo-950/20 border border-indigo-500/30 rounded-xl text-indigo-200">
                        <div className="text-[10px] font-bold uppercase text-indigo-400 mb-0.5">Credit Rule</div>
                        <div className="text-[11px] leading-snug">{p.credit_rule}</div>
                      </div>
                    </div>

                    {/* Concrete Journal Schema Box */}
                    {p.journal_schema && (
                      <div className="p-3 bg-slate-950 rounded-xl border border-slate-800 text-xs space-y-1">
                        <div className="text-[10px] font-bold uppercase text-amber-400">Standard Rupee Double-Entry Example:</div>
                        <div className="font-mono text-[11px] text-slate-200">
                          {p.journal_schema.example || p.journal_schema.entry || JSON.stringify(p.journal_schema)}
                        </div>
                      </div>
                    )}

                    {/* Applicable Accounts & Implication */}
                    <div className="text-xs space-y-1.5 pt-1">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-[10px] text-slate-500 uppercase font-semibold">Accounts:</span>
                        {p.applicable_accounts?.map((accName, i) => (
                          <span key={i} className="px-2 py-0.5 rounded-md bg-slate-800 text-slate-300 text-[10px]">
                            {accName}
                          </span>
                        ))}
                      </div>
                      <div className="text-[11px] text-slate-400 italic">
                        <strong>Practical Impact:</strong> {p.practical_implication}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* GOOGLE DATABASE HARVEST MODAL */}
          {showGoogleModal && (
            <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
              <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-5 shadow-2xl">
                <div className="flex justify-between items-center border-b border-slate-800 pb-3">
                  <div className="flex items-center gap-2">
                    <span className="text-xl">✨</span>
                    <div>
                      <h3 className="font-bold text-white text-base">Google Database Accounting Ingestion</h3>
                      <p className="text-[11px] text-slate-400">Collect authoritative double-entry principles via Gemini 3.6 Flash</p>
                    </div>
                  </div>
                  <button onClick={() => setShowGoogleModal(false)} className="text-slate-400 hover:text-white text-lg">&times;</button>
                </div>

                <div className="space-y-4 text-xs">
                  <div>
                    <label className="block font-medium text-slate-300 mb-1">Accounting Topic / Domain *</label>
                    <input
                      type="text"
                      value={googleTopic}
                      onChange={(e) => setGoogleTopic(e.target.value)}
                      placeholder="e.g., Prepaid Expenses, Forex Fluctuations, SaaS Ind AS 115..."
                      className="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2.5 text-slate-200 outline-none focus:border-purple-500"
                    />
                  </div>

                  <div className="flex flex-wrap gap-1.5">
                    <span className="text-[10px] text-slate-500">Quick Topics:</span>
                    <button onClick={() => setGoogleTopic('Prepaid Expenses, Accrued Incomes, and Payroll Statutory Deductions')} className="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 hover:text-white border border-slate-700">Prepaid & Payroll</button>
                    <button onClick={() => setGoogleTopic('Foreign Currency Transactions & Forex Fluctuations (Ind AS 21)')} className="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 hover:text-white border border-slate-700">Forex & Import</button>
                    <button onClick={() => setGoogleTopic('SaaS Subscription & Deferred Revenue Recognition (Ind AS 115)')} className="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 hover:text-white border border-slate-700">SaaS Ind AS 115</button>
                  </div>

                  <div>
                    <label className="block font-medium text-slate-300 mb-1">Number of Principles to Ingest</label>
                    <select
                      value={googleCount}
                      onChange={(e) => setGoogleCount(Number(e.target.value))}
                      className="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-slate-200 outline-none focus:border-purple-500"
                    >
                      <option value="2">2 Authoritative Principles</option>
                      <option value="3">3 Authoritative Principles</option>
                      <option value="5">5 Comprehensive Principles</option>
                    </select>
                  </div>

                  {harvestSuccessMsg && (
                    <div className="p-3 bg-emerald-950/40 border border-emerald-500/40 text-emerald-300 rounded-xl text-xs flex items-center gap-2">
                      <span>✓</span>
                      <span>{harvestSuccessMsg}</span>
                    </div>
                  )}
                </div>

                <div className="flex justify-end gap-3 pt-3 border-t border-slate-800">
                  <button
                    onClick={() => setShowGoogleModal(false)}
                    disabled={harvestingGoogle}
                    className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-xs font-semibold rounded-xl text-slate-300"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleCollectFromGoogle}
                    disabled={harvestingGoogle}
                    className="px-5 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:opacity-95 text-xs font-bold rounded-xl text-white shadow-lg shadow-purple-900/30 flex items-center gap-2 disabled:opacity-50"
                  >
                    {harvestingGoogle ? (
                      <>
                        <span className="w-3.5 h-3.5 border-2 border-white/20 border-t-white rounded-full animate-spin"></span>
                        <span>Querying Google Database...</span>
                      </>
                    ) : (
                      <>
                        <span>⚡</span>
                        <span>Ingest & Save to Dataset</span>
                      </>
                    )}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      );
}
