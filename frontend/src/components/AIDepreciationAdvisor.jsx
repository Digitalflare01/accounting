import React, { useState } from 'react';

export default function AIDepreciationAdvisor({ token, onDepreciationPosted, apiUrl = '/api' }) {

      const [promptText, setPromptText] = useState('');
      const [isResolving, setIsResolving] = useState(false);
      const [deprResult, setDeprResult] = useState(null);
      const [error, setError] = useState(null);
      const [successMsg, setSuccessMsg] = useState(null);
      const [isPostingEntry, setIsPostingEntry] = useState(false);

      const sampleQueries = [
        'I bought a Macbook for 1 lakh on August 25, what is the depreciation?',
        'Calculate depreciation on 2 lakhs of office furniture and chairs',
        'Commercial delivery vehicle purchased for 8 lakhs in November',
        'Server and network hardware 3.5 lakhs'
      ];

      const handleResolveDepreciation = async (e) => {
        if (e) e.preventDefault();
        if (!promptText.trim()) return;

        setIsResolving(true);
        setError(null);
        setSuccessMsg(null);
        setDeprResult(null);

        try {
          const res = await fetch(`${apiUrl}/ai/depreciation`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ prompt: promptText.trim() })
          });
          const json = await res.json();
          if (!res.ok || !json.success) {
            throw new Error(json.error || 'Failed to calculate statutory depreciation.');
          }
          setDeprResult(json);
        } catch (err) {
          setError(err.message);
        } finally {
          setIsResolving(false);
        }
      };

      const handlePostJournalEntry = async () => {
        if (!deprResult || !deprResult.suggested_journal_entry) return;

        setIsPostingEntry(true);
        setError(null);

        try {
          const entry = deprResult.suggested_journal_entry;
          const res = await fetch(`${apiUrl}/transactions/depreciation`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              amount: entry.amount,
              date: new Date().toISOString().split('T')[0],
              description: entry.narration,
              raw_prompt: promptText
            })
          });
          const json = await res.json();
          if (!res.ok || !json.success) {
            throw new Error(json.error || 'Failed to post depreciation entry.');
          }
          setSuccessMsg(`Depreciation entry posted to ledger: ₹${Number(entry.amount).toLocaleString('en-IN')} (Debit: Depreciation Expense, Credit: Accumulated Depreciation)`);
          if (onDepreciationPosted) onDepreciationPosted();
        } catch (err) {
          setError(err.message);
        } finally {
          setIsPostingEntry(false);
        }
      };

      return (
        <div className="w-full bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6">
          <div className="flex items-center justify-between pb-4 border-b border-slate-800">
            <div>
              <h2 className="text-xl sm:text-2xl font-extrabold bg-gradient-to-r from-emerald-400 via-teal-300 to-cyan-400 bg-clip-text text-transparent">
                AI Depreciation Advisor & Statutory Solver
              </h2>
              <p className="text-xs sm:text-sm text-slate-400 mt-1">
                Struggling to figure out depreciation rates, WDV vs SLM, or the 180-day tax rule? Ask in plain English.
              </p>
            </div>
            <span className="px-3 py-1 rounded-full text-xs font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
              Sec 32 IT Act & Companies Act
            </span>
          </div>

          {/* Sample Prompts */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Try:</span>
            {sampleQueries.map((q, i) => (
              <button
                key={i}
                type="button"
                onClick={() => setPromptText(q)}
                className="text-xs bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white px-3 py-1.5 rounded-lg border border-slate-700 transition"
              >
                "{q}"
              </button>
            ))}
          </div>

          {/* Form */}
          <form onSubmit={handleResolveDepreciation} className="relative">
            <textarea
              rows="2"
              value={promptText}
              onChange={(e) => setPromptText(e.target.value)}
              placeholder='e.g., "I bought a Macbook for 1 lakh on August 25, what is the depreciation?" or "How much can I write off for 2 lakhs furniture?"'
              className="w-full bg-slate-950 border border-slate-700 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-500 text-sm sm:text-base outline-none transition"
              disabled={isResolving}
            />
            <div className="absolute right-3 bottom-3">
              <button
                type="submit"
                disabled={isResolving || !promptText.trim()}
                className="inline-flex items-center gap-2 bg-gradient-to-r from-teal-500 to-cyan-600 hover:from-teal-600 hover:to-cyan-700 disabled:opacity-50 text-white text-xs sm:text-sm font-semibold px-4 py-2 rounded-lg shadow-lg shadow-cyan-500/25 transition"
              >
                {isResolving ? 'Calculating...' : 'Solve Depreciation'}
              </button>
            </div>
          </form>

          {error && <div className="p-3 bg-rose-950/40 border border-rose-500/40 text-rose-300 rounded-xl text-xs">{error}</div>}
          {successMsg && <div className="p-3 bg-emerald-950/40 border border-emerald-500/40 text-emerald-300 rounded-xl text-xs font-medium">✓ {successMsg}</div>}

          {/* Result Card */}
          {deprResult && (
            <div className="border border-slate-700 bg-slate-950 rounded-2xl p-5 sm:p-6 space-y-6">
              
              {/* Asset Header */}
              <div className="flex flex-wrap items-center justify-between gap-4 p-4 rounded-xl bg-slate-900 border border-slate-800">
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Identified Asset Category</div>
                  <div className="text-lg font-bold text-white">{deprResult.query_parsed.detected_category}</div>
                  <div className="text-xs text-slate-400 font-mono mt-0.5">{deprResult.query_parsed.statutory_block}</div>
                </div>
                <div>
                  <div className="text-[11px] uppercase text-slate-400">Cost Basis</div>
                  <div className="text-2xl font-black text-cyan-400">
                    ₹{Number(deprResult.query_parsed.detected_cost).toLocaleString('en-IN')}
                  </div>
                </div>
              </div>

              {/* Tax Rule Explanation */}
              <div className="p-4 rounded-xl bg-cyan-950/20 border border-cyan-500/30 text-xs sm:text-sm text-cyan-200">
                <div className="font-bold flex items-center gap-1.5 mb-1">
                  <span>💡 Statutory Tax Finding:</span>
                </div>
                <p>{deprResult.statutory_note}</p>
              </div>

              {/* Comparison: WDV (Income Tax Act) vs SLM (Companies Act) */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Method 1: Income Tax WDV */}
                <div className="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-2">
                    <span className="text-xs font-bold uppercase text-emerald-400">Income Tax Act: WDV Method</span>
                    <span className="text-xs font-mono bg-emerald-500/20 text-emerald-300 px-2 py-0.5 rounded font-bold">
                      {deprResult.rates.income_tax_wdv_rate}% Normal Rate
                    </span>
                  </div>
                  <div className="text-xs space-y-1.5 text-slate-300">
                    <div className="flex justify-between">
                      <span>Rate Applied Year 1:</span>
                      <strong className="text-white">{deprResult.depreciation_computations.income_tax_act_wdv.first_year_rate_applied}%</strong>
                    </div>
                    <div className="flex justify-between">
                      <span>Year 1 Tax Write-Off:</span>
                      <strong className="text-emerald-400">₹{Number(deprResult.depreciation_computations.income_tax_act_wdv.year_1_depreciation).toLocaleString('en-IN')}</strong>
                    </div>
                    <div className="flex justify-between">
                      <span>Year 1 Closing Net Book Value:</span>
                      <span>₹{Number(deprResult.depreciation_computations.income_tax_act_wdv.year_1_closing_nbv).toLocaleString('en-IN')}</span>
                    </div>
                    <div className="flex justify-between border-t border-slate-800 pt-1 text-slate-400">
                      <span>Year 2 Write-Off:</span>
                      <span>₹{Number(deprResult.depreciation_computations.income_tax_act_wdv.year_2_depreciation).toLocaleString('en-IN')}</span>
                    </div>
                  </div>
                </div>

                {/* Method 2: Companies Act SLM */}
                <div className="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-2">
                    <span className="text-xs font-bold uppercase text-blue-400">Companies Act: SLM Method</span>
                    <span className="text-xs font-mono bg-blue-500/20 text-blue-300 px-2 py-0.5 rounded font-bold">
                      {deprResult.rates.companies_act_useful_life_years} Years Useful Life
                    </span>
                  </div>
                  <div className="text-xs space-y-1.5 text-slate-300">
                    <div className="flex justify-between">
                      <span>Annual Straight-Line Rate:</span>
                      <strong className="text-white">{deprResult.rates.companies_act_slm_rate}% / year</strong>
                    </div>
                    <div className="flex justify-between">
                      <span>Annual Depreciation Expense:</span>
                      <strong className="text-blue-400">₹{Number(deprResult.depreciation_computations.companies_act_slm.annual_depreciation).toLocaleString('en-IN')}</strong>
                    </div>
                    <div className="flex justify-between">
                      <span>Monthly Accrual:</span>
                      <span>₹{Number(deprResult.depreciation_computations.companies_act_slm.monthly_depreciation).toLocaleString('en-IN')} / month</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Journal Entry Proposal & 1-Click Post */}
              <div className="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold uppercase text-slate-400 tracking-wider">Recommended Accounting Journal Entry</span>
                  <span className="text-xs text-indigo-400 font-mono">Balanced Double-Entry</span>
                </div>
                <div className="bg-slate-950 p-3 rounded-lg border border-slate-800 text-xs font-mono space-y-1.5 text-slate-300">
                  <div className="text-emerald-400">
                    Debit: [5070] Depreciation & Amortization Expense &rarr; ₹{Number(deprResult.suggested_journal_entry.amount).toLocaleString('en-IN')}
                  </div>
                  <div className="text-rose-400">
                    Credit: [1590] Accumulated Depreciation - Assets &rarr; ₹{Number(deprResult.suggested_journal_entry.amount).toLocaleString('en-IN')}
                  </div>
                  <div className="text-[11px] text-slate-500 pt-1">
                    Narration: {deprResult.suggested_journal_entry.narration}
                  </div>
                </div>

                <div className="flex justify-end pt-2">
                  <button
                    type="button"
                    onClick={handlePostJournalEntry}
                    disabled={isPostingEntry}
                    className="inline-flex items-center gap-2 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 disabled:opacity-50 text-white font-bold text-xs sm:text-sm px-6 py-2.5 rounded-xl shadow-lg shadow-emerald-500/25 transition"
                  >
                    {isPostingEntry ? 'Posting Entry...' : 'Post Depreciation Journal Entry to Ledger'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      );
}
