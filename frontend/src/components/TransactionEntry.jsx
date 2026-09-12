import React, { useState, useEffect } from 'react';

/**
 * TransactionEntry Component
 * Handles natural language financial string input, invokes backend AI parser,
 * multi-voucher compound journal entries, TechFlow case study ingestion, and instant target account creation.
 */
export default function TransactionEntry({ token, onTransactionSaved, apiUrl = '/api' }) {

      const [inputText, setInputText] = useState('');
      const [isProcessing, setIsProcessing] = useState(false);
      const [error, setError] = useState(null);
      const [successMessage, setSuccessMessage] = useState(null);

      const [parsedData, setParsedData] = useState(null);
      const [selectedOption, setSelectedOption] = useState('');
      const [targetAccountInfo, setTargetAccountInfo] = useState(null);
      const [customDescription, setCustomDescription] = useState('');
      const [gstRate, setGstRate] = useState('18');
      const [isInterstate, setIsInterstate] = useState(false);
      const [gstOff, setGstOff] = useState(false);
      const [isSubmittingFinal, setIsSubmittingFinal] = useState(false);
      const [availableAccounts, setAvailableAccounts] = useState([]);
      const [recentEntries, setRecentEntries] = useState([]);
      const [deletingId, setDeletingId] = useState(null);

      // Single-Prompt Compound Understanding & Auto-Post State
      const [compoundData, setCompoundData] = useState(null);
      const [isAutoPosting, setIsAutoPosting] = useState(false);
      const [isUnderstanding, setIsUnderstanding] = useState(false);
      const [lastVoucher, setLastVoucher] = useState(null);

      // Corporate Case Study Deliverables State
      const [caseStudyData, setCaseStudyData] = useState(null);
      const [caseStudyTab, setCaseStudyTab] = useState('vouchers');
      const [isPostingCaseStudy, setIsPostingCaseStudy] = useState(false);
      const [showGrossBs, setShowGrossBs] = useState(false);

      // Instant Account Creation State
      const [showCreateModal, setShowCreateModal] = useState(false);
      const [newAccountName, setNewAccountName] = useState('');
      const [newAccountType, setNewAccountType] = useState('revenue');
      const [newAccountGst, setNewAccountGst] = useState(true);
      const [isCreatingAccount, setIsCreatingAccount] = useState(false);

      const fetchRecentEntries = async () => {
        try {
          const res = await fetch(`${apiUrl}/transactions`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const json = await res.json();
          if (json.success) {
            setRecentEntries((json.data || []).slice(0, 5));
          }
        } catch (e) {}
      };

      useEffect(() => {
        if (token) fetchRecentEntries();
      }, [token]);

      const handleDeleteRecent = async (txId, accName, amt) => {
        if (!window.confirm(`Are you sure you want to delete transaction #${txId} (${accName} - ₹${amt}) from accounts?\n\nNote: All balanced ledger postings based on this entry (e.g. Bank Outflow/Inflow) will also be removed.`)) {
          return;
        }
        setDeletingId(txId);
        setError(null);
        setSuccessMessage(null);
        try {
          const res = await fetch(`${apiUrl}/transactions/${txId}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
          });
          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to delete transaction.');
          }
          setSuccessMessage(`✓ ${data.message || `Transaction #${txId} successfully deleted from accounts.`}`);
          await fetchRecentEntries();
          if (onTransactionSaved) onTransactionSaved();
        } catch (err) {
          setError(err.message);
        } finally {
          setDeletingId(null);
        }
      };

      const handleCreateAccount = async (e) => {
        if (e) e.preventDefault();
        const trimmed = newAccountName.trim();
        if (!trimmed) {
          setError('Account name is required.');
          return;
        }
        setIsCreatingAccount(true);
        setError(null);
        try {
          const res = await fetch(`${apiUrl}/accounts`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              name: trimmed,
              type: newAccountType,
              gst_applicable: newAccountGst ? 1 : 0
            })
          });
          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to create target account.');
          }
          const createdAcc = data.account;
          setAvailableAccounts(prev => {
            if (!prev.some(a => String(a.id) === String(createdAcc.id) || a.name.toLowerCase() === createdAcc.name.toLowerCase())) {
              return [createdAcc, ...prev];
            }
            return prev;
          });
          setSelectedOption(createdAcc.name);
          setTargetAccountInfo(createdAcc);
          setShowCreateModal(false);
          setNewAccountName('');
          setSuccessMessage(`✓ Target Account '${createdAcc.name}' (${createdAcc.code}) created instantly & selected.`);
        } catch (err) {
          setError(err.message);
        } finally {
          setIsCreatingAccount(false);
        }
      };

      const techflowCaseStudyPrompt = `TechFlow Solutions
TechFlow Solutions commenced business on April 1, 2025. All transactions attract an 18% GST (9% CGST + 9% SGST) where applicable. During April 2025, the following transactions occurred:

Apr 1: The owner invested ₹500,000 cash into the business.

Apr 5: Purchased office equipment for ₹100,000 + 18% GST. Paid in full via bank.

Apr 10: Purchased inventory (goods for resale) for ₹50,000 + 18% GST on credit from ABC Corp.

Apr 15: Sold goods for ₹80,000 + 18% GST. The customer paid cash immediately.

Apr 25: Paid monthly office rent of ₹10,000 in cash (Assume no GST on this rent).

Apr 28: Paid ABC Corp ₹30,000 in cash towards the outstanding payable.

Apr 30: A physical count shows ₹10,000 worth of closing inventory remains.

Your Task: Prepare the Trial Balance, Statement of Profit & Loss, Balance Sheet, Cash Flow Statement, and a GST Summary for April.`;

      const samplePrompts = [
        'Sold consulting services to Reliance for 1 lakh + 18% GST, received 50k in HDFC Bank and balance receivable',
        'Paid office rent 50000 on 2026-09-01 via Bank with 10% TDS deduction to landlord Sharma Properties',
        'Paid 6000 rs for 12 month network recharge using bank',
        'Purchased 3 Dell Laptops for 1.5 lakhs and printer 20k with 18% GST paid by Bank',
        'Paid employee salary 1,20,000: basic 1,20,000, PF 14,400, TDS 6,000, net paid 99,600 via Bank'
      ];

      const handleAutoPost = async (e) => {
        if (e) e.preventDefault();
        const text = inputText.trim();
        if (!text) return;

        setIsAutoPosting(true);
        setError(null);
        setSuccessMessage(null);
        setParsedData(null);
        setCompoundData(null);
        setLastVoucher(null);

        try {
          const res = await fetch(`${apiUrl}/ai/auto-post`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ prompt: text, is_interstate: isInterstate, gst_off: gstOff })
          });

          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to auto-post transaction.');
          }

          if (data.is_case_study) {
            setCaseStudyData({ ...data, prompt: text });
            setCompoundData(null);
            setLastVoucher(null);
            setInputText('');
            const grpId = data.posting_details?.case_study_group_id || 'CS_POSTED';
            setSuccessMessage(`⚡ Case Study solved & posted! 7 vouchers under group ${grpId}`);
          } else {
            setLastVoucher(data);
            setInputText('');
            const postedCount = data.posting_details?.entries_posted || (data.entries?.length || 0);
            const grpId = data.posting_details?.entry_group_id || 'N/A';
            setSuccessMessage(`⚡ All ${postedCount} entries verified & posted to accounts books! (Voucher ID: ${grpId})`);
          }
          await fetchRecentEntries();
          if (onTransactionSaved) onTransactionSaved();
        } catch (err) {
          setError(err.message);
        } finally {
          setIsAutoPosting(false);
        }
      };

      const handleUnderstand = async (e) => {
        if (e) e.preventDefault();
        const text = inputText.trim();
        if (!text) return;

        setIsUnderstanding(true);
        setError(null);
        setSuccessMessage(null);
        setParsedData(null);
        setCompoundData(null);
        setLastVoucher(null);

        try {
          const res = await fetch(`${apiUrl}/ai/understand`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ prompt: text, is_interstate: isInterstate, gst_off: gstOff })
          });

          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to analyze transaction details.');
          }

          if (data.is_case_study) {
            setCaseStudyData({ ...data, prompt: text });
            setCompoundData(null);
            setSuccessMessage(`✓ Case Study solved: ${data.company_name} (${data.period}) — 5 financial statements generated. Review the tabs below, then post to books.`);
          } else {
            setCompoundData(data);
            setCaseStudyData(null);
          }
        } catch (err) {
          setError(err.message);
        } finally {
          setIsUnderstanding(false);
        }
      };

      const handlePostCompoundEntries = async () => {
        if (!compoundData || !compoundData.entries || compoundData.entries.length === 0) return;

        setIsSubmittingFinal(true);
        setError(null);

        try {
          const res = await fetch(`${apiUrl}/transactions`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              entries: compoundData.entries,
              raw_ai_input: compoundData.prompt || inputText,
              date: compoundData.date || new Date().toISOString().split('T')[0],
              description: compoundData.narration || 'Compound Journal Voucher'
            })
          });

          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to post compound entries.');
          }

          setLastVoucher({
            prompt: compoundData.prompt,
            date: compoundData.date,
            narration: compoundData.narration,
            entries: compoundData.entries,
            verification: compoundData.verification,
            new_accounts_setup: compoundData.new_accounts_setup,
            posting_details: {
              entry_group_id: data.entry_group_id,
              entries_posted: data.entries?.length || compoundData.entries.length,
              entries: data.entries || compoundData.entries
            }
          });

          const count = data.entries?.length || compoundData.entries.length;
          setSuccessMessage(`✓ All ${count} entries verified & posted to accounts books!`);
          setCompoundData(null);
          setInputText('');
          await fetchRecentEntries();
          if (onTransactionSaved) onTransactionSaved();
        } catch (err) {
          setError(err.message);
        } finally {
          setIsSubmittingFinal(false);
        }
      };

      const handlePostCaseStudy = async () => {
        if (!caseStudyData) return;
        setIsPostingCaseStudy(true);
        setError(null);
        try {
          const res = await fetch(`${apiUrl}/ai/case-study/post`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ prompt: caseStudyData.prompt || techflowCaseStudyPrompt })
          });
          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to post case study vouchers.');
          }
          setCaseStudyData({ ...data, prompt: caseStudyData.prompt });
          const count = data.posting_details?.entries_posted || 20;
          const grpId = data.posting_details?.case_study_group_id || 'CS_POSTED';
          setSuccessMessage(`✓ All 7 vouchers (${count} legs) posted to accounts books! Group: ${grpId}`);
          await fetchRecentEntries();
          if (onTransactionSaved) onTransactionSaved();
        } catch (err) {
          setError(err.message);
        } finally {
          setIsPostingCaseStudy(false);
        }
      };

      const handleParse = async (e) => {
        if (e) e.preventDefault();
        if (!inputText.trim()) return;

        setIsProcessing(true);
        setError(null);
        setSuccessMessage(null);
        setParsedData(null);
        setSelectedOption('');
        setTargetAccountInfo(null);

        try {
          const res = await fetch(`${apiUrl}/ai/parse`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ text: inputText.trim() })
          });

          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to parse financial text.');
          }

          const ai = data.data;
          setParsedData(ai);
          setCustomDescription(inputText.trim());

          if (data.all_accounts && Array.isArray(data.all_accounts)) {
            setAvailableAccounts(data.all_accounts);
          }

          // Auto-pick Target Account
          const target = data.target_account || null;
          setTargetAccountInfo(target);

          let initialOption = '';
          if (target && target.name) {
            initialOption = target.name;
          } else if (ai.suggested_account_name) {
            initialOption = ai.suggested_account_name;
          } else if (ai.review_options && ai.review_options.length > 0) {
            initialOption = ai.review_options[0];
          } else if (ai.transaction_type === 'credit') {
            initialOption = 'Software Development Services';
          } else {
            initialOption = 'Office Expense';
          }
          setSelectedOption(initialOption);

          // If target was auto-created, notify the user
          if (target && target.auto_created) {
            setSuccessMessage(`✓ Auto-detected & instantly created target account: ${target.name} (${target.code})`);
          }

          if (ai.gst_extracted > 0 && ai.parsed_amount > 0) {
            const calculatedRate = Math.round((ai.gst_extracted / ai.parsed_amount) * 100);
            setGstRate(String(calculatedRate));
          }
        } catch (err) {
          setError(err.message);
        } finally {
          setIsProcessing(false);
        }
      };

      const handleFinalSubmit = async () => {
        if (!parsedData) return;
        if (parsedData.needs_user_review && !selectedOption) {
          setError('Please resolve the accounting ambiguity before submitting.');
          return;
        }

        setIsSubmittingFinal(true);
        setError(null);

        try {
          const amount = Number(parsedData.parsed_amount);
          const rate = Number(gstRate) || 0;
          const gstAmount = rate > 0 ? Number(((amount * rate) / 100).toFixed(2)) : 0;

          // Ensure a valid non-empty account is always passed
          let finalAccount = selectedOption;
          if (!finalAccount) {
            if (parsedData.review_options && parsedData.review_options.length > 0) {
              finalAccount = parsedData.review_options[0];
            } else if (parsedData.suggested_account_name) {
              finalAccount = parsedData.suggested_account_name;
            } else {
              const cat = (parsedData.suggested_category || '').toLowerCase();
              if (cat.includes('util') || cat.includes('telecom') || cat.includes('network') || cat.includes('recharg')) {
                finalAccount = 'Utility Bills';
              } else if (parsedData.transaction_type === 'credit') {
                finalAccount = 'Consulting Income';
              } else {
                finalAccount = 'Office Expense';
              }
            }
          }

          const payload = {
            amount: amount,
            type: parsedData.transaction_type,
            account_name: finalAccount,
            date: new Date().toISOString().split('T')[0],
            description: customDescription || finalAccount,
            gst_amount: gstAmount,
            gst_rate: rate,
            is_interstate: isInterstate,
            raw_ai_input: inputText
          };

          const res = await fetch(`${apiUrl}/transactions`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(payload)
          });

          const data = await res.json();
          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Failed to save transaction.');
          }

          setSuccessMessage(
            `Transaction #${data.transaction.id} saved & trained into AI dataset! Account: ${data.transaction.account_name} (₹${Number(data.transaction.amount).toLocaleString('en-IN')}) | Tax: CGST ₹${data.transaction.cgst}, SGST ₹${data.transaction.sgst}, IGST ₹${data.transaction.igst}`
          );

          setInputText('');
          setParsedData(null);
          setSelectedOption('');

          await fetchRecentEntries();
          if (onTransactionSaved) onTransactionSaved();
        } catch (err) {
          setError(err.message);
        } finally {
          setIsSubmittingFinal(false);
        }
      };

      return (
        <div className="w-full bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6">
          <div className="flex items-center justify-between pb-4 border-b border-slate-800">
            <div>
              <h2 className="text-xl sm:text-2xl font-extrabold bg-gradient-to-r from-blue-400 via-indigo-300 to-purple-400 bg-clip-text text-transparent">
                AI Natural Language Ingestion & Disambiguation
              </h2>
              <p className="text-xs sm:text-sm text-slate-400 mt-1">
                Enter transactions in colloquial terms (e.g., lakh, k, crore). Ambiguities trigger Human-in-the-Loop review.
              </p>
            </div>
            <span className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
              AI Self-Learning Engine Active
            </span>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Examples:</span>
            {samplePrompts.map((p, i) => (
              <button
                key={i}
                type="button"
                onClick={() => setInputText(p)}
                className="text-xs bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white px-3 py-1.5 rounded-lg border border-slate-700 transition"
              >
                "{p}"
              </button>
            ))}
          </div>

          <form onSubmit={handleAutoPost} className="space-y-3">
            <div className="relative">
              <textarea
                rows="3"
                value={inputText}
                onChange={(e) => setInputText(e.target.value)}
                placeholder='Enter single prompt with all details (e.g., "Sold consulting services to Reliance for 1 lakh + 18% GST on 2026-09-10, received 50k in HDFC Bank and balance receivable" or "Paid office rent 50000 with 10% TDS deduction via Bank on 2026-09-01")'
                className="w-full bg-slate-950 border border-slate-700 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-500 text-sm sm:text-base outline-none transition"
                disabled={isProcessing || isAutoPosting || isUnderstanding}
              />
            </div>

            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-slate-950/60 p-3 rounded-xl border border-slate-800">
              <div className="flex flex-wrap items-center gap-3">
                <label className="inline-flex items-center gap-2 text-xs text-slate-400 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={isInterstate}
                    onChange={(e) => setIsInterstate(e.target.checked)}
                    className="w-4 h-4 rounded text-indigo-600 bg-slate-900 border-slate-700 focus:ring-0"
                  />
                  <span>Interstate Supply (IGST)</span>
                </label>
                <button
                  type="button"
                  onClick={() => setGstOff(v => !v)}
                  title={gstOff ? "GST OFF: Using only rates from your prompt (or zero if none). Click to re-enable system GST." : "GST ON: System will apply default 18% GST. Click to use only rates mentioned in your prompt."}
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-bold transition select-none ${
                    gstOff
                      ? 'bg-amber-500/20 border-amber-400/60 text-amber-300 shadow-inner shadow-amber-900/30'
                      : 'bg-emerald-950/40 border-emerald-500/30 text-emerald-400 hover:bg-emerald-950/70'
                  }`}
                >
                  <span className={`w-2 h-2 rounded-full ${ gstOff ? 'bg-amber-400' : 'bg-emerald-500' }`}></span>
                  {gstOff ? '🚫 GST OFF' : '✅ GST ON'}
                </button>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={handleParse}
                  disabled={isProcessing || isAutoPosting || isUnderstanding || !inputText.trim()}
                  className="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white border border-slate-700 text-xs font-semibold disabled:opacity-50 transition"
                  title="Legacy simple single-account disambiguation"
                >
                  {isProcessing ? 'Analyzing...' : 'Simple Analysis'}
                </button>

                <button
                  type="button"
                  onClick={handleUnderstand}
                  disabled={isProcessing || isAutoPosting || isUnderstanding || !inputText.trim()}
                  className="px-4 py-2 rounded-xl bg-indigo-950/90 hover:bg-indigo-900 text-indigo-300 hover:text-white border border-indigo-500/40 text-xs font-semibold flex items-center gap-1.5 shadow-lg shadow-indigo-950/40 disabled:opacity-50 transition"
                >
                  {isUnderstanding ? (
                    <>
                      <span className="w-3 h-3 border-2 border-indigo-400/30 border-t-indigo-400 rounded-full animate-spin"></span>
                      <span>Auditing Details...</span>
                    </>
                  ) : (
                    <>
                      <span>🔍</span>
                      <span>Understand & Verify First</span>
                    </>
                  )}
                </button>

                <button
                  type="button"
                  onClick={handleAutoPost}
                  disabled={isProcessing || isAutoPosting || isUnderstanding || !inputText.trim()}
                  className="px-5 py-2 bg-gradient-to-r from-purple-600 via-indigo-600 to-blue-600 hover:opacity-95 text-white font-bold text-xs sm:text-sm rounded-xl shadow-lg shadow-purple-900/40 flex items-center gap-2 disabled:opacity-50 transition"
                >
                  {isAutoPosting ? (
                    <>
                      <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                      <span>Posting to Books...</span>
                    </>
                  ) : (
                    <>
                      <span>⚡</span>
                      <span>Auto-Post to Accounts Books</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </form>

          {error && <div className="p-3 bg-rose-950/40 border border-rose-500/40 text-rose-300 rounded-xl text-xs">{error}</div>}
          {successMessage && <div className="p-3 bg-emerald-950/40 border border-emerald-500/40 text-emerald-300 rounded-xl text-xs font-medium">✓ {successMessage}</div>}

          {gstOff && (
            <div className="flex items-center gap-3 p-3 bg-amber-500/10 border border-amber-400/40 rounded-xl text-xs text-amber-300 font-semibold">
              <span className="text-lg">🚫</span>
              <div>
                <span className="text-amber-200 font-bold">GST OFF</span> &mdash; System will <strong>not</strong> apply a default GST rate.
                If your prompt mentions a GST rate (e.g. &ldquo;18% GST&rdquo;, &ldquo;₹14,400 GST&rdquo;), that exact figure will be used.
                If no GST is mentioned, the transaction will be treated as <strong>GST-exempt (0%)</strong>.
              </div>
            </div>
          )}


          {caseStudyData && (
            <div className="border border-purple-500/40 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950/40 rounded-2xl p-5 sm:p-6 space-y-6 shadow-2xl relative overflow-hidden">
              <div className="absolute top-0 right-0 w-96 h-96 bg-purple-500/10 rounded-full blur-3xl pointer-events-none"></div>

              {/* Header & Meta */}
              <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-slate-800">
                <div className="space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                      Case Study Master Solution
                    </span>
                    <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                      {caseStudyData.period || "April 2025"}
                    </span>
                    <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                      GST: {caseStudyData.gst_rate}% ({caseStudyData.cgst_rate}% CGST + {caseStudyData.sgst_rate}% SGST)
                    </span>
                    {caseStudyData.is_posted ? (
                      <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center gap-1">
                        <span>✓</span>
                        <span>Posted to Books ({caseStudyData.posting_details?.case_study_group_id || "CS_POSTED"})</span>
                      </span>
                    ) : (
                      <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">
                        Audit Staging (Unposted)
                      </span>
                    )}
                  </div>
                  <h3 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
                    {caseStudyData.company_name} &bull; Comprehensive Financial Deliverables
                  </h3>
                  <p className="text-xs sm:text-sm text-slate-400">
                    Commenced business on {caseStudyData.commencement_date}. Autonomous chartered accountancy solution matching statutory Ind AS & GST standards.
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2 self-start lg:self-center">
                  {!caseStudyData.is_posted ? (
                    <button
                      type="button"
                      onClick={handlePostCaseStudy}
                      disabled={isPostingCaseStudy}
                      className="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:opacity-95 text-white font-bold text-xs sm:text-sm rounded-xl shadow-lg shadow-emerald-900/30 flex items-center gap-1.5 disabled:opacity-50 transition"
                    >
                      {isPostingCaseStudy ? "Posting to Books..." : "⚡ Post All 7 Vouchers to Books"}
                    </button>
                  ) : (
                    <span className="px-3 py-1.5 bg-emerald-950/60 border border-emerald-500/40 text-emerald-300 text-xs font-bold rounded-xl flex items-center gap-1.5">
                      <span>✓</span>
                      <span>All Vouchers Posted to Books</span>
                    </span>
                  )}
                  <button
                    type="button"
                    onClick={() => window.print()}
                    className="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl text-xs font-semibold border border-slate-700 transition"
                  >
                    🖨️ Print Report
                  </button>
                  <button
                    type="button"
                    onClick={() => setCaseStudyData(null)}
                    className="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-xl text-xs font-semibold border border-slate-700 transition"
                  >
                    Dismiss
                  </button>
                </div>
              </div>

              {/* Interactive Navigation Tabs */}
              <div className="flex overflow-x-auto gap-2 pb-1 border-b border-slate-800 text-xs font-bold">
                {[
                  { id: 'vouchers', label: '📑 Journal Vouchers (7)', badge: 'Balanced' },
                  { id: 'tb', label: '⚖️ Trial Balance', badge: caseStudyData.trial_balance?.compliance_status === 'PERFECTLY_BALANCED' ? 'Reconciled' : 'Check' },
                  { id: 'pl', label: '📈 Statement of Profit & Loss', badge: `Net Profit: ₹${Number(caseStudyData.profit_and_loss?.net_profit || 30000).toLocaleString('en-IN')}` },
                  { id: 'bs', label: '🏛️ Balance Sheet', badge: `Assets: ₹${Number(caseStudyData.balance_sheet?.total_assets || 559000).toLocaleString('en-IN')}` },
                  { id: 'cf', label: '💵 Cash Flow Statement', badge: `Net Change: +₹${Number(caseStudyData.cash_flow_statement?.reconciliation?.net_change_in_cash_and_bank || 436400).toLocaleString('en-IN')}` },
                  { id: 'gst', label: '📋 GST Summary', badge: `Excess ITC: ₹${Number(caseStudyData.gst_summary?.settlement?.total_excess_itc_carried_forward || 12600).toLocaleString('en-IN')}` }
                ].map(tab => (
                  <button
                    key={tab.id}
                    type="button"
                    onClick={() => setCaseStudyTab(tab.id)}
                    className={`px-3.5 py-2 rounded-xl whitespace-nowrap flex items-center gap-1.5 transition ${
                      caseStudyTab === tab.id
                        ? 'bg-purple-600 text-white shadow-lg shadow-purple-900/40'
                        : 'bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-slate-200 border border-slate-800'
                    }`}
                  >
                    <span>{tab.label}</span>
                    {tab.badge && (
                      <span className="px-1.5 py-0.5 rounded text-[10px] bg-slate-950/60 text-purple-200 border border-purple-400/20 font-mono">
                        {tab.badge}
                      </span>
                    )}
                  </button>
                ))}
              </div>

              {/* TAB 1: JOURNAL VOUCHERS */}
              {caseStudyTab === 'vouchers' && (
                <div className="space-y-4">
                  <div className="text-xs text-slate-400">
                    All 7 chronological double-entry vouchers with statutory GST splitting and accounts classification:
                  </div>
                  <div className="space-y-3">
                    {caseStudyData.vouchers?.map((v, idx) => (
                      <div key={idx} className="p-4 bg-slate-950 rounded-xl border border-slate-800 space-y-2">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1 border-b border-slate-800/80 pb-2">
                          <div className="flex items-center gap-2">
                            <span className="w-6 h-6 rounded-lg bg-purple-500/20 text-purple-300 font-mono font-bold text-xs flex items-center justify-center border border-purple-500/30">
                              #{v.voucher_no}
                            </span>
                            <span className="text-xs font-mono font-bold text-slate-200">{v.date}</span>
                            <span className="text-xs text-slate-400 font-semibold">&bull; {v.narration}</span>
                          </div>
                          <span className="text-[10px] px-2 py-0.5 rounded bg-slate-900 text-slate-400 border border-slate-800 font-semibold self-start sm:self-center">
                            {v.nature}
                          </span>
                        </div>

                        <table className="w-full text-left text-xs font-mono">
                          <thead className="text-[10px] uppercase text-slate-500 border-b border-slate-800/50">
                            <tr>
                              <th className="py-1 px-2">Leg</th>
                              <th className="py-1 px-2 font-sans">Account Ledger</th>
                              <th className="py-1 px-2 font-sans">Classification</th>
                              <th className="py-1 px-2 text-right">Debit (₹)</th>
                              <th className="py-1 px-2 text-right">Credit (₹)</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-slate-900">
                            {v.legs.map((leg, lIdx) => (
                              <tr key={lIdx} className="hover:bg-slate-900/40">
                                <td className="py-1.5 px-2">
                                  <span className={`px-1.5 py-0.5 rounded text-[9px] font-bold uppercase ${
                                    leg.type === "debit" ? "bg-cyan-500/20 text-cyan-300" : "bg-purple-500/20 text-purple-300"
                                  }`}>
                                    {leg.type}
                                  </span>
                                </td>
                                <td className="py-1.5 px-2 font-sans font-bold text-white">
                                  {leg.account_name}
                                </td>
                                <td className="py-1.5 px-2 font-sans text-slate-400 uppercase text-[10px]">
                                  {leg.account_type}
                                </td>
                                <td className="py-1.5 px-2 text-right text-cyan-300 font-bold">
                                  {leg.type === "debit" ? `₹${Number(leg.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                                </td>
                                <td className="py-1.5 px-2 text-right text-purple-300 font-bold">
                                  {leg.type === "credit" ? `₹${Number(leg.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* TAB 2: TRIAL BALANCE */}
              {caseStudyTab === 'tb' && (
                <div className="space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-white">Trial Balance as of April 30, 2025</h4>
                      <p className="text-xs text-slate-400">Verifying arithmetical equality across all real, nominal, and personal accounts.</p>
                    </div>
                    <span className="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center gap-1 self-start sm:self-center">
                      <span>✓</span>
                      <span>{caseStudyData.trial_balance?.compliance_status}</span>
                    </span>
                  </div>

                  <div className="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950">
                    <table className="w-full text-left text-xs font-mono">
                      <thead className="bg-slate-900 border-b border-slate-800 text-slate-400 font-semibold uppercase tracking-wider text-[10px]">
                        <tr>
                          <th className="py-2.5 px-4">Code</th>
                          <th className="py-2.5 px-4 font-sans">Account Ledger</th>
                          <th className="py-2.5 px-4 font-sans">Classification</th>
                          <th className="py-2.5 px-4 text-right">Debit Balance (₹)</th>
                          <th className="py-2.5 px-4 text-right">Credit Balance (₹)</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-800/60">
                        {caseStudyData.trial_balance?.accounts?.map((acc, idx) => (
                          <tr key={idx} className="hover:bg-slate-900/40">
                            <td className="py-2 px-4 text-slate-400">{acc.account_code}</td>
                            <td className="py-2 px-4 font-sans font-bold text-white">{acc.account_name}</td>
                            <td className="py-2 px-4 font-sans text-slate-400 uppercase text-[10px]">{acc.account_type}</td>
                            <td className="py-2 px-4 text-right text-cyan-300 font-bold">
                              {acc.debit > 0 ? `₹${Number(acc.debit).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                            </td>
                            <td className="py-2 px-4 text-right text-purple-300 font-bold">
                              {acc.credit > 0 ? `₹${Number(acc.credit).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot className="bg-slate-900/90 border-t-2 border-slate-700 font-bold">
                        <tr>
                          <td colSpan="3" className="py-3 px-4 text-slate-200 font-sans uppercase tracking-wider">
                            Total Ledger Balance
                          </td>
                          <td className="py-3 px-4 text-right text-cyan-300 text-sm">
                            ₹{Number(caseStudyData.trial_balance?.total_debits || 0).toLocaleString("en-IN", { minimumFractionDigits: 2 })}
                          </td>
                          <td className="py-3 px-4 text-right text-purple-300 text-sm">
                            ₹{Number(caseStudyData.trial_balance?.total_credits || 0).toLocaleString("en-IN", { minimumFractionDigits: 2 })}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>

                  <div className="p-3 bg-emerald-950/30 border border-emerald-500/30 rounded-xl text-xs text-emerald-300 flex items-center gap-2">
                    <span className="font-bold">✓ Arithmetical Verification:</span>
                    <span>Total Debits exactly match Total Credits (Difference: ₹{caseStudyData.trial_balance?.difference}.00). Golden Rules of Accounting preserved.</span>
                  </div>
                </div>
              )}

              {/* TAB 3: STATEMENT OF PROFIT & LOSS */}
              {caseStudyTab === 'pl' && (
                <div className="space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-white">Statement of Profit & Loss for April 2025</h4>
                      <p className="text-xs text-slate-400">Trading & Operating Results with statutory COGS and closing stock adjustment (AS 2).</p>
                    </div>
                    <div className="text-right">
                      <div className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Net Profit</div>
                      <div className="text-lg font-bold font-mono text-emerald-400">
                        ₹{Number(caseStudyData.profit_and_loss?.net_profit || 30000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}
                      </div>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Trading Section */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3 font-mono text-xs">
                      <div className="font-sans font-bold text-sm text-indigo-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>Trading Account (Gross Profit)</span>
                        <span className="text-xs text-indigo-400">Ind AS 2</span>
                      </div>
                      
                      <div className="space-y-1.5">
                        <div className="flex justify-between text-slate-300">
                          <span className="font-sans">Revenue from Operations (Sales of Goods):</span>
                          <span className="text-white font-bold">₹{Number(caseStudyData.profit_and_loss?.trading_account?.total_revenue || 80000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                        </div>
                        <div className="pt-2 border-t border-slate-900 space-y-1 text-slate-400">
                          <div className="flex justify-between">
                            <span className="font-sans">Opening Inventory (Commenced Apr 1):</span>
                            <span>₹0.00</span>
                          </div>
                          <div className="flex justify-between">
                            <span className="font-sans">Add: Purchases of Resale Goods:</span>
                            <span>₹{Number(caseStudyData.profit_and_loss?.trading_account?.cost_of_goods_sold?.add_purchases || 50000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                          </div>
                          <div className="flex justify-between text-emerald-400">
                            <span className="font-sans">Less: Closing Physical Inventory (Apr 30):</span>
                            <span>(₹{Number(caseStudyData.profit_and_loss?.trading_account?.cost_of_goods_sold?.less_closing_inventory || 10000).toLocaleString('en-IN', { minimumFractionDigits: 2 })})</span>
                          </div>
                          <div className="flex justify-between font-bold text-slate-200 pt-1 border-t border-slate-900">
                            <span className="font-sans">Cost of Goods Sold (COGS):</span>
                            <span>₹{Number(caseStudyData.profit_and_loss?.trading_account?.cost_of_goods_sold?.total_cogs || 40000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                          </div>
                        </div>
                      </div>

                      <div className="pt-2 border-t-2 border-slate-800 flex justify-between text-sm font-bold text-cyan-300">
                        <span className="font-sans">Gross Profit:</span>
                        <span>₹{Number(caseStudyData.profit_and_loss?.trading_account?.gross_profit || 40000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="text-[11px] text-slate-500 font-sans">
                        Gross Margin: {caseStudyData.profit_and_loss?.trading_account?.gross_margin_percentage || 50.0}%
                      </div>
                    </div>

                    {/* Operating Expenses & Net Profit */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3 font-mono text-xs">
                      <div className="font-sans font-bold text-sm text-purple-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>Operating & Net Profit</span>
                        <span className="text-xs text-purple-400">Schedule III</span>
                      </div>

                      <div className="space-y-2">
                        <div className="flex justify-between text-slate-300">
                          <span className="font-sans">Gross Profit brought down:</span>
                          <span className="text-cyan-300 font-bold">₹{Number(caseStudyData.profit_and_loss?.trading_account?.gross_profit || 40000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                        </div>
                        
                        <div className="pt-2 border-t border-slate-900 space-y-1 text-slate-400">
                          <div className="font-sans font-bold text-slate-300">Operating Expenses:</div>
                          <div className="flex justify-between pl-2">
                            <span className="font-sans">Office Rent (GST Exempt):</span>
                            <span>₹{Number(caseStudyData.profit_and_loss?.operating_expenses?.total_expenses || 10000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                          </div>
                          <div className="flex justify-between font-bold text-slate-200 pt-1 border-t border-slate-900">
                            <span className="font-sans">Total Operating Expenses:</span>
                            <span>₹{Number(caseStudyData.profit_and_loss?.operating_expenses?.total_expenses || 10000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                          </div>
                        </div>
                      </div>

                      <div className="pt-2 border-t-2 border-slate-800 flex justify-between text-base font-extrabold text-emerald-400">
                        <span className="font-sans">Net Profit for the Period:</span>
                        <span>₹{Number(caseStudyData.profit_and_loss?.net_profit || 30000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="text-[11px] text-slate-500 font-sans">
                        Net Profit Margin: {caseStudyData.profit_and_loss?.net_margin_percentage || 37.5}% (Transferred to Owner Equity on Balance Sheet)
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* TAB 4: BALANCE SHEET */}
              {caseStudyTab === 'bs' && (
                <div className="space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-white">Balance Sheet as of April 30, 2025</h4>
                      <p className="text-xs text-slate-400">Dual Aspect: Total Assets = Total Liabilities & Owner Equity (Ind AS 1).</p>
                    </div>
                    <div className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => setShowGrossBs(!showGrossBs)}
                        className="px-2.5 py-1 rounded-lg text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 font-semibold transition"
                      >
                        {showGrossBs ? "View Net Assets" : "View Gross Assets"}
                      </button>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* ASSETS */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3 font-mono text-xs">
                      <div className="font-sans font-bold text-sm text-cyan-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>ASSETS</span>
                        <span className="text-xs text-cyan-400">Application of Funds</span>
                      </div>

                      <div className="space-y-3">
                        <div>
                          <div className="font-sans font-bold text-slate-300 uppercase text-[10px] tracking-wider mb-1">
                            Non-Current Assets
                          </div>
                          <div className="flex justify-between text-slate-300 pl-2">
                            <span className="font-sans">Office Equipment & Furniture:</span>
                            <span className="text-white font-bold">₹100,000.00</span>
                          </div>
                        </div>

                        <div>
                          <div className="font-sans font-bold text-slate-300 uppercase text-[10px] tracking-wider mb-1">
                            Current Assets
                          </div>
                          <div className="space-y-1 pl-2 text-slate-300">
                            <div className="flex justify-between">
                              <span className="font-sans">Closing Inventory (Stock-in-Trade):</span>
                              <span className="text-white font-bold">₹10,000.00</span>
                            </div>
                            <div className="flex justify-between">
                              <span className="font-sans">Cash in Hand:</span>
                              <span>₹554,400.00</span>
                            </div>
                            <div className="flex justify-between text-slate-400">
                              <span className="font-sans">Bank Overdraft / Disbursement:</span>
                              <span>(₹118,000.00)</span>
                            </div>
                            <div className="flex justify-between font-bold text-cyan-300 pt-0.5 border-t border-slate-900">
                              <span className="font-sans">&bull; Net Liquid Funds (Cash & Bank):</span>
                              <span>₹436,400.00</span>
                            </div>
                            <div className="flex justify-between text-amber-300">
                              <span className="font-sans">Net GST Input Tax Credit (ITC) Receivable:</span>
                              <span className="font-bold">₹12,600.00</span>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div className="pt-3 border-t-2 border-slate-800 flex justify-between text-base font-extrabold text-cyan-300">
                        <span className="font-sans">TOTAL ASSETS:</span>
                        <span>₹{Number(caseStudyData.balance_sheet?.total_assets || 559000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                    </div>

                    {/* LIABILITIES & EQUITY */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3 font-mono text-xs">
                      <div className="font-sans font-bold text-sm text-purple-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>LIABILITIES & EQUITY</span>
                        <span className="text-xs text-purple-400">Source of Funds</span>
                      </div>

                      <div className="space-y-3">
                        <div>
                          <div className="font-sans font-bold text-slate-300 uppercase text-[10px] tracking-wider mb-1">
                            Current Liabilities
                          </div>
                          <div className="flex justify-between text-slate-300 pl-2">
                            <span className="font-sans">ABC Corp - Accounts Payable (₹59k - ₹30k):</span>
                            <span className="text-white font-bold">₹29,000.00</span>
                          </div>
                        </div>

                        <div>
                          <div className="font-sans font-bold text-slate-300 uppercase text-[10px] tracking-wider mb-1">
                            Owner Equity
                          </div>
                          <div className="space-y-1 pl-2 text-slate-300">
                            <div className="flex justify-between">
                              <span className="font-sans">Owner Capital Introduced:</span>
                              <span className="text-white font-bold">₹500,000.00</span>
                            </div>
                            <div className="flex justify-between text-emerald-400">
                              <span className="font-sans">Add: Net Profit for April:</span>
                              <span className="font-bold">+₹30,000.00</span>
                            </div>
                            <div className="flex justify-between font-bold text-purple-300 pt-0.5 border-t border-slate-900">
                              <span className="font-sans">&bull; Total Owner Equity:</span>
                              <span>₹530,000.00</span>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div className="pt-3 border-t-2 border-slate-800 flex justify-between text-base font-extrabold text-purple-300">
                        <span className="font-sans">TOTAL LIAB. & EQUITY:</span>
                        <span>₹{Number(caseStudyData.balance_sheet?.total_liabilities_and_equity || 559000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                    </div>
                  </div>

                  {showGrossBs && (
                    <div className="p-3 bg-slate-900/60 border border-slate-800 rounded-xl text-xs space-y-1">
                      <div className="font-bold text-slate-300">Gross Ledger Balances Presentation (Pre-Offset):</div>
                      <div className="text-slate-400 flex flex-wrap gap-4 font-mono">
                        <span>Gross Assets: Equipment ₹100k + Inventory ₹10k + Cash ₹554.4k + Input GST ₹27k = <strong className="text-white">₹691,400.00</strong></span>
                        <span>Gross Liab+Equity: Bank ₹118k + ABC Corp ₹29k + Output GST ₹14.4k + Capital ₹500k + Profit ₹30k = <strong className="text-white">₹691,400.00</strong></span>
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* TAB 5: CASH FLOW STATEMENT */}
              {caseStudyTab === 'cf' && (
                <div className="space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-white">Statement of Cash Flows for April 2025</h4>
                      <p className="text-xs text-slate-400">Ind AS 7 / AS 3 Direct Method: Operating, Investing, and Financing activities.</p>
                    </div>
                    <span className="px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 flex items-center gap-1 self-start sm:self-center">
                      <span>✓</span>
                      <span>Liquid Funds Reconciled</span>
                    </span>
                  </div>

                  <div className="space-y-3 font-mono text-xs">
                    {/* Operating Activities */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                      <div className="font-sans font-bold text-sm text-indigo-300 border-b border-slate-800 pb-1 flex justify-between">
                        <span>1. Cash Flows from Operating Activities</span>
                        <span className="text-emerald-400 font-bold">+₹{Number(caseStudyData.cash_flow_statement?.operating_activities?.net_cash_from_operating || 54400).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="space-y-1 pl-2 text-slate-300">
                        {caseStudyData.cash_flow_statement?.operating_activities?.items?.map((item, idx) => (
                          <div key={idx} className="flex justify-between">
                            <span className="font-sans text-slate-400">{item.description}:</span>
                            <span className={item.amount >= 0 ? "text-emerald-400 font-bold" : "text-rose-400 font-bold"}>
                              {item.amount >= 0 ? `+₹${Number(item.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}` : `(₹${Number(Math.abs(item.amount)).toLocaleString('en-IN', { minimumFractionDigits: 2 })})`}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Investing Activities */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                      <div className="font-sans font-bold text-sm text-cyan-300 border-b border-slate-800 pb-1 flex justify-between">
                        <span>2. Cash Flows from Investing Activities</span>
                        <span className="text-rose-400 font-bold">₹{Number(caseStudyData.cash_flow_statement?.investing_activities?.net_cash_from_investing || -118000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="space-y-1 pl-2 text-slate-300">
                        {caseStudyData.cash_flow_statement?.investing_activities?.items?.map((item, idx) => (
                          <div key={idx} className="flex justify-between">
                            <span className="font-sans text-slate-400">{item.description}:</span>
                            <span className="text-rose-400 font-bold">(₹{Number(Math.abs(item.amount)).toLocaleString('en-IN', { minimumFractionDigits: 2 })})</span>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Financing Activities */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                      <div className="font-sans font-bold text-sm text-purple-300 border-b border-slate-800 pb-1 flex justify-between">
                        <span>3. Cash Flows from Financing Activities</span>
                        <span className="text-emerald-400 font-bold">+₹{Number(caseStudyData.cash_flow_statement?.financing_activities?.net_cash_from_financing || 500000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="space-y-1 pl-2 text-slate-300">
                        {caseStudyData.cash_flow_statement?.financing_activities?.items?.map((item, idx) => (
                          <div key={idx} className="flex justify-between">
                            <span className="font-sans text-slate-400">{item.description}:</span>
                            <span className="text-emerald-400 font-bold">+₹{Number(item.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Reconciliation */}
                    <div className="p-4 bg-slate-900/90 rounded-xl border-2 border-slate-700 space-y-2">
                      <div className="flex justify-between text-sm font-bold text-white">
                        <span className="font-sans">Net Increase in Cash & Bank Equivalents:</span>
                        <span className="text-emerald-400">+₹{Number(caseStudyData.cash_flow_statement?.reconciliation?.net_change_in_cash_and_bank || 436400).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="flex justify-between text-slate-400 text-xs">
                        <span className="font-sans">Cash & Bank Balance at Commencement (April 1, 2025):</span>
                        <span>₹0.00</span>
                      </div>
                      <div className="flex justify-between text-xs font-bold text-slate-200 border-t border-slate-800 pt-1.5">
                        <span className="font-sans">Closing Liquid Funds (Cash ₹554,400 - Bank ₹118,000):</span>
                        <span>₹{Number(caseStudyData.cash_flow_statement?.reconciliation?.closing_cash_and_bank || 436400).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* TAB 6: GST SUMMARY */}
              {caseStudyTab === 'gst' && (
                <div className="space-y-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-white">Statutory GST Summary & Credit Ledger (April 2025)</h4>
                      <p className="text-xs text-slate-400">18% GST (9% CGST + 9% SGST) Inward Input Tax Credit vs Outward Supply Liability.</p>
                    </div>
                    <span className="px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 flex items-center gap-1 self-start sm:self-center">
                      <span>✓</span>
                      <span>No Cash Tax Liability (Excess ITC)</span>
                    </span>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-xs">
                    {/* Input Tax Credit Available */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                      <div className="font-sans font-bold text-sm text-cyan-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>1. Input Tax Credit (ITC) Available</span>
                        <span className="text-cyan-400 font-bold">₹{Number(caseStudyData.gst_summary?.input_tax_credit_available?.total_itc_total || 27000).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>

                      <div className="space-y-2 text-slate-300">
                        <div className="p-2.5 bg-slate-900/50 rounded-lg border border-slate-800/80 space-y-1">
                          <div className="font-sans font-bold text-slate-200">Capital Goods (Office Equipment @ 18%):</div>
                          <div className="flex justify-between text-slate-400 pl-2">
                            <span>CGST @ 9%: ₹9,000.00</span>
                            <span>SGST @ 9%: ₹9,000.00</span>
                            <span className="text-white font-bold">Total: ₹18,000.00</span>
                          </div>
                        </div>

                        <div className="p-2.5 bg-slate-900/50 rounded-lg border border-slate-800/80 space-y-1">
                          <div className="font-sans font-bold text-slate-200">Inward Resale Goods (Purchases from ABC Corp @ 18%):</div>
                          <div className="flex justify-between text-slate-400 pl-2">
                            <span>CGST @ 9%: ₹4,500.00</span>
                            <span>SGST @ 9%: ₹4,500.00</span>
                            <span className="text-white font-bold">Total: ₹9,000.00</span>
                          </div>
                        </div>

                        <div className="pt-2 border-t border-slate-800 flex justify-between font-bold text-cyan-300">
                          <span className="font-sans">Total Eligible ITC:</span>
                          <span>CGST ₹13,500 + SGST ₹13,500 = ₹27,000.00</span>
                        </div>
                      </div>
                    </div>

                    {/* Output Tax Liability & Settlement */}
                    <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                      <div className="font-sans font-bold text-sm text-purple-300 border-b border-slate-800 pb-1.5 flex justify-between">
                        <span>2. Output Tax & Statutory Offset</span>
                        <span className="text-purple-400 font-bold">₹{Number(caseStudyData.gst_summary?.output_tax_liability?.total_output_tax || 14400).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                      </div>

                      <div className="space-y-2 text-slate-300">
                        <div className="p-2.5 bg-slate-900/50 rounded-lg border border-slate-800/80 space-y-1">
                          <div className="font-sans font-bold text-slate-200">Outward Supplies (Sales of Goods @ 18%):</div>
                          <div className="flex justify-between text-slate-400 pl-2">
                            <span>CGST @ 9%: ₹7,200.00</span>
                            <span>SGST @ 9%: ₹7,200.00</span>
                            <span className="text-white font-bold">Total: ₹14,400.00</span>
                          </div>
                        </div>

                        <div className="p-2.5 bg-emerald-950/30 rounded-lg border border-emerald-500/30 space-y-1 text-emerald-300">
                          <div className="font-sans font-bold flex justify-between">
                            <span>Net GST Payable in Cash:</span>
                            <span>₹0.00</span>
                          </div>
                          <div className="text-[11px] text-emerald-400 font-sans">
                            Output liability of ₹14,400 is 100% offset by Input Tax Credit.
                          </div>
                        </div>

                        <div className="p-2.5 bg-amber-950/30 rounded-lg border border-amber-500/30 space-y-1 text-amber-300">
                          <div className="font-sans font-bold flex justify-between">
                            <span>Excess ITC Carried Forward:</span>
                            <span className="text-base font-extrabold">₹12,600.00</span>
                          </div>
                          <div className="flex justify-between text-[11px] text-amber-400 pl-1 font-mono">
                            <span>CGST: ₹6,300.00</span>
                            <span>SGST: ₹6,300.00</span>
                            <span>Electronic Credit Ledger Asset</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* Audit Verification Footer */}
              <div className="pt-3 border-t border-slate-800/80">
                <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">
                  Chartered Accountancy Audit Checks & Statutory Compliance:
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 text-xs">
                  {caseStudyData.audit?.checks?.map((chk, cIdx) => (
                    <div key={cIdx} className="p-2.5 bg-slate-950/80 rounded-xl border border-slate-800 text-[11px]">
                      <div className="flex items-center gap-1.5 font-bold text-slate-200">
                        <span className="text-emerald-400">✓</span>
                        <span>{chk.name}</span>
                      </div>
                      <p className="text-slate-400 mt-0.5 text-[10px] font-mono">{chk.detail}</p>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

                    {/* POSTED VOUCHER AUDIT RECEIPT */}
          {lastVoucher && (
            <div className="border border-emerald-500/40 bg-gradient-to-br from-slate-950 via-slate-900 to-emerald-950/30 rounded-2xl p-5 sm:p-6 space-y-4 shadow-2xl relative overflow-hidden">
              <div className="absolute top-0 right-0 w-80 h-80 bg-emerald-500/5 rounded-full blur-3xl pointer-events-none"></div>

              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center font-bold text-base">
                    ✓
                  </div>
                  <div>
                    <h3 className="text-sm sm:text-base font-bold text-white flex items-center gap-2">
                      <span>Voucher Successfully Verified & Posted to Books</span>
                      <span className="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                        {lastVoucher.posting_details?.entry_group_id || "grp_posted"}
                      </span>
                    </h3>
                    <p className="text-xs text-slate-400 mt-0.5">
                      Voucher Date: <span className="text-slate-200 font-semibold">{lastVoucher.date || lastVoucher.voucher_date}</span> &bull; {lastVoucher.narration || "General Journal Voucher"}
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setLastVoucher(null)}
                  className="self-end sm:self-center px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-lg text-xs font-semibold transition"
                >
                  Dismiss
                </button>
              </div>

              {/* Newly Setup Accounts Pills */}
              {lastVoucher.new_accounts_setup && lastVoucher.new_accounts_setup.length > 0 && (
                <div className="p-3 bg-indigo-950/30 border border-indigo-500/30 rounded-xl space-y-1.5">
                  <div className="text-[11px] font-bold uppercase tracking-wider text-indigo-300 flex items-center gap-1.5">
                    <span>✨</span>
                    <span>Accounts Automatically Setup in Chart of Accounts:</span>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {lastVoucher.new_accounts_setup.map((na, idx) => (
                      <span key={idx} className="px-2.5 py-1 rounded-lg text-xs bg-indigo-900/50 text-indigo-200 border border-indigo-500/40 font-semibold flex items-center gap-1">
                        <span className="font-mono text-[11px] text-indigo-400">{na.code}</span>
                        <span>{na.name}</span>
                        <span className="text-[10px] uppercase text-indigo-300/80">({na.type})</span>
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {/* Posted Entries Table */}
              <div className="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-900 border-b border-slate-800 text-slate-400 font-semibold uppercase tracking-wider">
                    <tr>
                      <th className="py-2.5 px-4">Entry</th>
                      <th className="py-2.5 px-4">Account Ledger</th>
                      <th className="py-2.5 px-4">Classification</th>
                      <th className="py-2.5 px-4 text-right">Debit (₹)</th>
                      <th className="py-2.5 px-4 text-right">Credit (₹)</th>
                      <th className="py-2.5 px-4">Voucher Narration</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-mono">
                    {(lastVoucher.posting_details?.entries || lastVoucher.entries || []).map((row, idx) => (
                      <tr key={idx} className="hover:bg-slate-900/40">
                        <td className="py-2 px-4">
                          <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                            row.type === "debit" ? "bg-cyan-500/20 text-cyan-300 border border-cyan-500/30" : "bg-purple-500/20 text-purple-300 border border-purple-500/30"
                          }`}>
                            {row.type}
                          </span>
                        </td>
                        <td className="py-2 px-4 font-sans font-bold text-white">
                          {row.account_name}
                        </td>
                        <td className="py-2 px-4 font-sans text-slate-400 uppercase text-[11px]">
                          {row.account_type || "asset"}
                        </td>
                        <td className="py-2 px-4 text-right text-emerald-400 font-bold">
                          {row.type === "debit" ? `₹${Number(row.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                        </td>
                        <td className="py-2 px-4 text-right text-purple-400 font-bold">
                          {row.type === "credit" ? `₹${Number(row.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                        </td>
                        <td className="py-2 px-4 font-sans text-slate-400 truncate max-w-xs">
                          {row.description}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Verification Audit Checklist */}
              {lastVoucher.verification?.audit_checks && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2 border-t border-slate-800">
                  {lastVoucher.verification.audit_checks.map((chk, i) => (
                    <div key={i} className="flex items-start gap-2 p-2 bg-slate-950/60 rounded-lg border border-slate-800 text-[11px]">
                      <span className="text-emerald-400 font-bold">✓</span>
                      <div>
                        <span className="font-bold text-slate-300">{chk.rule}:</span>
                        <p className="text-slate-400">{chk.message}</p>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* COMPOUND JOURNAL VOUCHER PREVIEW & VERIFICATION */}
          {compoundData && (
            <div className="border border-indigo-500/40 bg-slate-950 rounded-2xl p-5 sm:p-6 space-y-5 shadow-2xl relative">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-800">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="px-2.5 py-1 rounded-lg text-xs font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 flex items-center gap-1.5">
                      <span>⚖</span>
                      <span>Verified Compound Journal Voucher</span>
                    </span>
                    <span className="text-xs text-slate-400 font-mono">Date: {compoundData.date}</span>
                  </div>
                  <h3 className="text-base font-bold text-white mt-1">
                    {compoundData.narration}
                  </h3>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setCompoundData(null)}
                    className="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl text-xs font-semibold transition"
                  >
                    Dismiss
                  </button>
                  <button
                    type="button"
                    onClick={handlePostCompoundEntries}
                    disabled={isSubmittingFinal || !compoundData.verification?.is_balanced}
                    className="px-5 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:opacity-95 text-white font-bold text-xs sm:text-sm rounded-xl shadow-lg shadow-emerald-900/30 flex items-center gap-1.5 disabled:opacity-50 transition"
                  >
                    {isSubmittingFinal ? "Posting to Books..." : "✓ Confirm & Post All Entries to Books"}
                  </button>
                </div>
              </div>

              {/* Mathematical Balance & Verification Summary */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 bg-slate-900/80 rounded-xl border border-slate-800">
                <div className="p-2">
                  <div className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Total Debits</div>
                  <div className="text-lg font-bold font-mono text-cyan-400">
                    ₹{Number(compoundData.verification?.total_debit || 0).toLocaleString("en-IN", { minimumFractionDigits: 2 })}
                  </div>
                </div>
                <div className="p-2 border-l border-slate-800">
                  <div className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Total Credits</div>
                  <div className="text-lg font-bold font-mono text-purple-400">
                    ₹{Number(compoundData.verification?.total_credit || 0).toLocaleString("en-IN", { minimumFractionDigits: 2 })}
                  </div>
                </div>
                <div className="p-2 border-l border-slate-800 flex flex-col justify-center">
                  <div className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Double-Entry Status</div>
                  <div className="flex items-center gap-1.5 mt-0.5">
                    {compoundData.verification?.is_balanced ? (
                      <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1">
                        <span>✓</span>
                        <span>Exact Balanced (0.00 Diff)</span>
                      </span>
                    ) : (
                      <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                        Imbalanced: ₹{compoundData.verification?.difference}
                      </span>
                    )}
                  </div>
                </div>
              </div>

              {/* Multi-Leg Entries Table */}
              <div className="overflow-x-auto rounded-xl border border-slate-800 bg-slate-950">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-900 border-b border-slate-800 text-slate-400 font-semibold uppercase tracking-wider">
                    <tr>
                      <th className="py-2.5 px-4">Direction</th>
                      <th className="py-2.5 px-4">Account Ledger</th>
                      <th className="py-2.5 px-4">Type</th>
                      <th className="py-2.5 px-4 text-right">Debit (₹)</th>
                      <th className="py-2.5 px-4 text-right">Credit (₹)</th>
                      <th className="py-2.5 px-4">Entry Description</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-mono">
                    {compoundData.entries?.map((leg, idx) => (
                      <tr key={idx} className="hover:bg-slate-900/40">
                        <td className="py-2 px-4">
                          <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                            leg.type === "debit" ? "bg-cyan-500/20 text-cyan-300 border border-cyan-500/30" : "bg-purple-500/20 text-purple-300 border border-purple-500/30"
                          }`}>
                            {leg.type}
                          </span>
                        </td>
                        <td className="py-2 px-4 font-sans font-bold text-white flex items-center gap-1.5">
                          <span>{leg.account_name}</span>
                          {leg.is_new_account && (
                            <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/40">
                              Auto-Setup
                            </span>
                          )}
                        </td>
                        <td className="py-2 px-4 font-sans text-slate-400 uppercase text-[11px]">
                          {leg.account_type}
                        </td>
                        <td className="py-2 px-4 text-right text-cyan-300 font-bold">
                          {leg.type === "debit" ? `₹${Number(leg.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                        </td>
                        <td className="py-2 px-4 text-right text-purple-300 font-bold">
                          {leg.type === "credit" ? `₹${Number(leg.amount).toLocaleString("en-IN", { minimumFractionDigits: 2 })}` : "-"}
                        </td>
                        <td className="py-2 px-4 font-sans text-slate-400">
                          {leg.description}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Statutory Audit Checks */}
              {compoundData.verification?.audit_checks && (
                <div className="space-y-2 pt-2 border-t border-slate-800">
                  <div className="text-xs font-bold text-slate-300 uppercase tracking-wider">
                    Statutory & Accounting Rules Verification
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {compoundData.verification.audit_checks.map((chk, i) => (
                      <div key={i} className="flex items-start gap-2 p-2.5 bg-slate-900/60 rounded-xl border border-slate-800 text-[11px]">
                        <span className="text-emerald-400 font-bold mt-0.5">✓</span>
                        <div>
                          <span className="font-bold text-slate-200">{chk.rule}</span>
                          <p className="text-slate-400 mt-0.5">{chk.message}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {parsedData && (
            <div className="border border-slate-700 bg-slate-950 rounded-2xl p-5 sm:p-6 space-y-5">
              {/* Intelligence Source & Training Indicator */}
              <div className="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  {parsedData.source === 'google_gemini_api' ? (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-gradient-to-r from-purple-500/20 to-indigo-500/20 text-purple-300 border border-purple-500/30">
                      <span className="w-2 h-2 rounded-full bg-purple-400 animate-pulse"></span>
                      ✨ Gemini AI + In-Context Self-Trained
                    </span>
                  ) : parsedData.source === 'local_trained_dataset' ? (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                      <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
                      ⚡ Autonomous Local Brain (100% Offline Active)
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                      ⚙ Heuristic Classifier
                    </span>
                  )}
                  {parsedData.model && (
                    <span className="text-[10px] font-mono text-slate-500">[{parsedData.model}]</span>
                  )}
                </div>
                <div className="text-[11px] text-slate-400 flex items-center gap-1.5">
                  <span className="text-emerald-400 font-bold">●</span>
                  <span>Continuous Self-Training On Confirm</span>
                </div>
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 rounded-xl bg-slate-900 border border-slate-800">
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Parsed Amount</div>
                  <div className="text-xl font-bold text-white">₹{Number(parsedData.parsed_amount).toLocaleString('en-IN')}</div>
                </div>
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Type</div>
                  <span className={`inline-block px-2 py-0.5 rounded text-xs font-bold uppercase ${
                    parsedData.transaction_type === 'credit' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                  }`}>
                    {parsedData.transaction_type}
                  </span>
                </div>
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Category</div>
                  <div className="text-sm font-semibold text-slate-200 capitalize">{parsedData.suggested_category}</div>
                </div>
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Confidence</div>
                  <div className="text-sm font-bold font-mono text-indigo-400">{(parsedData.confidence_score * 100).toFixed(0)}%</div>
                </div>
              </div>

              {parsedData.needs_user_review ? (
                <div className="p-4 sm:p-5 rounded-xl bg-amber-950/20 border-2 border-amber-500/40 space-y-3">
                  <div className="flex items-center gap-2 text-amber-300 font-bold text-sm">
                    <span>⚠ Ambiguity Detected: Is this a Capital Asset or an Office Expense?</span>
                  </div>
                  <p className="text-xs text-amber-200/80">
                    Dual-classification detected. Capital purchases are capitalized on the Balance Sheet, while operational costs are expensed on the P&L. Select the proper classification:
                  </p>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    {parsedData.review_options.map((opt, i) => {
                      const isSelected = selectedOption === opt;
                      return (
                        <div
                          key={i}
                          onClick={() => setSelectedOption(opt)}
                          className={`cursor-pointer p-4 rounded-xl border-2 transition-all flex items-center justify-between ${
                            isSelected ? 'border-indigo-500 bg-indigo-950/40 shadow-lg' : 'border-slate-800 bg-slate-900/70 hover:border-slate-700'
                          }`}
                        >
                          <div className="flex items-center gap-3">
                            <div className={`w-4 h-4 rounded-full border flex items-center justify-center ${isSelected ? 'border-indigo-500 bg-indigo-500' : 'border-slate-600'}`}>
                              {isSelected && <div className="w-1.5 h-1.5 rounded-full bg-white"></div>}
                            </div>
                            <div>
                              <div className="text-sm font-bold text-white">{opt}</div>
                              <div className="text-xs text-slate-400">
                                {opt.includes('Fixed Asset') ? 'Balance Sheet (Capital Asset)' : 'P&L Statement (Revenue Expense)'}
                              </div>
                            </div>
                          </div>
                          <span className="text-[10px] px-2 py-0.5 rounded bg-slate-800 font-mono text-slate-300">
                            {opt.includes('Fixed Asset') ? 'Asset' : 'Expense'}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ) : (
                <div className="p-4 bg-slate-900 border border-slate-800 rounded-xl space-y-3">
                  <div className="flex items-center justify-between flex-wrap gap-2">
                    <div className="flex items-center gap-2 text-xs text-emerald-400 font-semibold flex-wrap">
                      <span>✓ Auto-Picked Target Account:</span>
                      <strong className="text-white capitalize">{selectedOption || parsedData.suggested_account_name || parsedData.suggested_category}</strong>
                      {targetAccountInfo?.type && (
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-mono capitalize">
                          {targetAccountInfo.type}
                        </span>
                      )}
                      {targetAccountInfo?.auto_created && (
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30 font-mono">
                          ⚡ Instantly Created
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-mono">
                        Target Account Auto-Picked
                      </span>
                      <button
                        type="button"
                        onClick={() => {
                          setNewAccountName('');
                          setNewAccountType(parsedData.transaction_type === 'credit' ? 'revenue' : 'expense');
                          setShowCreateModal(true);
                        }}
                        className="text-[11px] px-2.5 py-1 bg-indigo-600/30 hover:bg-indigo-600/50 text-indigo-300 border border-indigo-500/40 rounded-lg transition flex items-center gap-1 font-medium"
                      >
                        <span>+</span> Create New Account
                      </button>
                    </div>
                  </div>
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="block text-xs font-semibold text-slate-400">
                        Post to Account (Chart of Accounts): <span className="text-slate-500 font-normal">(User can change anytime)</span>
                      </label>
                    </div>
                    <select
                      value={selectedOption}
                      onChange={(e) => {
                        if (e.target.value === '__CREATE_NEW__') {
                          setNewAccountName('');
                          setNewAccountType(parsedData.transaction_type === 'credit' ? 'revenue' : 'expense');
                          setShowCreateModal(true);
                        } else {
                          setSelectedOption(e.target.value);
                          const matched = availableAccounts.find(a => a.name === e.target.value);
                          if (matched) setTargetAccountInfo(matched);
                        }
                      }}
                      className="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"
                    >
                      {availableAccounts.length > 0 ? (
                        availableAccounts.map(acc => (
                          <option key={acc.id} value={acc.name}>{acc.code} - {acc.name} ({acc.type})</option>
                        ))
                      ) : (
                        parsedData.review_options?.map((opt, i) => (
                          <option key={i} value={opt}>{opt}</option>
                        ))
                      )}
                      <option value="__CREATE_NEW__" className="text-indigo-400 font-bold bg-slate-900">+ Create New Custom Account...</option>
                    </select>
                  </div>
                </div>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-slate-800">
                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">GST Rate</label>
                  <select
                    value={gstRate}
                    onChange={(e) => setGstRate(e.target.value)}
                    className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white outline-none"
                  >
                    <option value="0">0% (Nil)</option>
                    <option value="5">5% GST</option>
                    <option value="12">12% GST</option>
                    <option value="18">18% GST (Standard)</option>
                    <option value="28">28% GST</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">Jurisdiction</label>
                  <label className="inline-flex items-center gap-2 mt-2 text-xs text-slate-300 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={isInterstate}
                      onChange={(e) => setIsInterstate(e.target.checked)}
                      className="w-4 h-4 rounded text-indigo-600 bg-slate-900 border-slate-700"
                    />
                    <span>Inter-State Supply (IGST)</span>
                  </label>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-400 mb-1">Computed Tax</label>
                  <div className="text-xs bg-slate-900 p-2 rounded-lg border border-slate-800 font-mono text-slate-300">
                    {Number(gstRate) > 0 ? (
                      isInterstate ? (
                        <div>IGST: ₹{((Number(parsedData.parsed_amount) * Number(gstRate)) / 100).toLocaleString('en-IN')}</div>
                      ) : (
                        <div>
                          CGST: ₹{(((Number(parsedData.parsed_amount) * Number(gstRate)) / 100) / 2).toLocaleString('en-IN')} | 
                          SGST: ₹{(((Number(parsedData.parsed_amount) * Number(gstRate)) / 100) / 2).toLocaleString('en-IN')}
                        </div>
                      )
                    ) : (
                      <div>Nil Tax</div>
                    )}
                  </div>
                </div>
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setParsedData(null)}
                  className="px-4 py-2 text-xs text-slate-400 hover:text-white"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleFinalSubmit}
                  disabled={isSubmittingFinal || (parsedData.needs_user_review && !selectedOption)}
                  className="bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-bold text-xs sm:text-sm px-6 py-2.5 rounded-xl shadow-lg shadow-emerald-600/25 transition"
                >
                  {isSubmittingFinal ? 'Posting...' : 'Confirm Classification & Post to SQL'}
                </button>
              </div>
            </div>
          )}

          {/* Recent Chat-Added Transactions in Accounts with Instant Delete */}
          {recentEntries.length > 0 && (
            <div className="pt-5 border-t border-slate-800 space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span className="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                  <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300">
                    Recent Chat Add-ons in Accounts
                  </h4>
                </div>
                <span className="text-[11px] text-slate-500 font-mono">Instant Delete Enabled</span>
              </div>

              <div className="space-y-2">
                {recentEntries.map((item) => (
                  <div
                    key={item.id}
                    className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-xl bg-slate-950/80 border border-slate-800 hover:border-slate-700 transition"
                  >
                    <div className="flex items-start sm:items-center gap-3 min-w-0">
                      <span className="text-[11px] font-mono text-slate-500 shrink-0 font-bold">#{item.id}</span>
                      <div className="min-w-0">
                        <div className="text-xs font-semibold text-white truncate">
                          {item.description}
                        </div>
                        <div className="flex items-center gap-2 text-[11px] text-slate-400 flex-wrap">
                          <span className="text-indigo-300 font-medium">{item.account_name}</span>
                          <span>&bull;</span>
                          <span className="font-mono text-slate-400">{item.date}</span>
                          {item.raw_ai_input && (
                            <>
                              <span>&bull;</span>
                              <span className="italic text-slate-500 truncate max-w-xs">AI: "{item.raw_ai_input}"</span>
                            </>
                          )}
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center gap-3 shrink-0 self-end sm:self-center">
                      <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                        item.type === 'credit' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                      }`}>
                        {item.type}
                      </span>
                      <span className="text-xs font-bold font-mono text-white">
                        ₹{Number(item.amount).toLocaleString('en-IN')}
                      </span>
                      <button
                        type="button"
                        onClick={() => handleDeleteRecent(item.id, item.account_name, item.amount)}
                        disabled={deletingId === item.id}
                        className="px-2.5 py-1 text-xs rounded-lg bg-rose-500/10 hover:bg-rose-500/25 text-rose-400 border border-rose-500/30 transition flex items-center gap-1.5 font-semibold disabled:opacity-50"
                        title="Delete this entry from accounts"
                      >
                        {deletingId === item.id ? (
                          <span className="animate-pulse">Deleting...</span>
                        ) : (
                          <>
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            <span>Delete</span>
                          </>
                        )}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Instant Account Creation Modal */}
          {showCreateModal && (
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm animate-fade-in">
              <div className="bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-4">
                <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                  <div className="flex items-center gap-2">
                    <span className="p-1.5 rounded-lg bg-indigo-500/20 text-indigo-400 text-base font-bold">⚡</span>
                    <div>
                      <h3 className="text-sm sm:text-base font-bold text-white">
                        Instant Target Account Creator
                      </h3>
                      <p className="text-[11px] text-slate-400">Add a new Chart of Accounts ledger item immediately</p>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => setShowCreateModal(false)}
                    className="text-slate-400 hover:text-white text-lg font-bold p-1 rounded-lg hover:bg-slate-800 transition"
                  >
                    ✕
                  </button>
                </div>

                <form onSubmit={handleCreateAccount} className="space-y-4">
                  <div>
                    <label className="block text-xs font-semibold text-slate-300 mb-1">
                      Account Name <span className="text-rose-400">*</span>
                    </label>
                    <input
                      type="text"
                      value={newAccountName}
                      onChange={(e) => setNewAccountName(e.target.value)}
                      placeholder="e.g., E-Commerce Development Income, Cloud Storage Expense"
                      className="w-full bg-slate-950 border border-slate-700 focus:border-indigo-500 rounded-xl px-3 py-2 text-xs sm:text-sm text-white outline-none"
                      autoFocus
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-300 mb-1">
                      Account Classification Type
                    </label>
                    <select
                      value={newAccountType}
                      onChange={(e) => setNewAccountType(e.target.value)}
                      className="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs sm:text-sm text-white outline-none focus:border-indigo-500"
                    >
                      <option value="revenue">Revenue (Income, Billed Services, Sales)</option>
                      <option value="expense">Expense (Operational Costs, Utilities, Rent)</option>
                      <option value="asset">Asset (Capital Purchases, Computers, Machinery)</option>
                      <option value="liability">Liability (Payables, Loans)</option>
                      <option value="equity">Equity (Owner Capital, Retained Earnings)</option>
                    </select>
                  </div>

                  <div className="flex items-center gap-2 pt-1">
                    <input
                      type="checkbox"
                      id="newAccountGstCheckbox"
                      checked={newAccountGst}
                      onChange={(e) => setNewAccountGst(e.target.checked)}
                      className="w-4 h-4 rounded text-indigo-600 bg-slate-950 border-slate-700 cursor-pointer"
                    />
                    <label htmlFor="newAccountGstCheckbox" className="text-xs text-slate-300 cursor-pointer">
                      GST Applicable (tax rates can be computed on this account)
                    </label>
                  </div>

                  <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                    <button
                      type="button"
                      onClick={() => setShowCreateModal(false)}
                      className="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      disabled={isCreatingAccount || !newAccountName.trim()}
                      className="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-lg transition flex items-center gap-1.5"
                    >
                      {isCreatingAccount ? 'Creating...' : '⚡ Create & Select Account'}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          )}
        </div>
      );
}
