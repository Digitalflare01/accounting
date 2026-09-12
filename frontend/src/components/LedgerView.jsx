import React, { useState, useEffect } from 'react';

/**
 * LedgerView Component
 * Renders the full journal and transaction ledger with real-time status,
 * Chart of Account mapping, CGST/SGST/IGST breakdown, and delete management.
 */
export default function LedgerView({ token, refreshTrigger, apiUrl = '/api', onTransactionChanged }) {
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [successMsg, setSuccessMsg] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [searchQuery, setSearchQuery] = useState('');

  const fetchTransactions = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${apiUrl}/transactions`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const json = await res.json();
      if (!res.ok || !json.success) throw new Error(json.error || 'Failed to fetch ledger transactions');
      setTransactions(json.data || []);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTransactions();
  }, [refreshTrigger]);

  const handleDelete = async (txId, accName, amt) => {
    if (!window.confirm(`Are you sure you want to permanently delete Transaction #${txId} (${accName} - ₹${Number(amt).toLocaleString('en-IN')}) from accounts?\n\nNote: All balanced ledger postings based on this entry (e.g. Bank Outflow/Inflow or contra accounts) will also be removed.`)) {
      return;
    }
    setDeletingId(txId);
    setError(null);
    setSuccessMsg(null);
    try {
      const res = await fetch(`${apiUrl}/transactions/${txId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Failed to delete transaction.');
      }
      setSuccessMsg(`✓ ${data.message || `Transaction #${txId} deleted permanently from ledger.`}`);
      await fetchTransactions();
      if (onTransactionChanged) onTransactionChanged();
    } catch (err) {
      setError(err.message);
    } finally {
      setDeletingId(null);
    }
  };

  const handleClearAll = async () => {
    if (!window.confirm('CAUTION: Are you sure you want to delete ALL entered transactions from your accounts? This will wipe all chat add-ons permanently.')) {
      return;
    }
    setLoading(true);
    setError(null);
    setSuccessMsg(null);
    try {
      const res = await fetch(`${apiUrl}/transactions/clear-all`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Failed to clear transactions.');
      }
      setSuccessMsg(`✓ ${data.message}`);
      await fetchTransactions();
      if (onTransactionChanged) onTransactionChanged();
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const filteredTransactions = transactions.filter((t) => {
    if (!searchQuery.trim()) return true;
    const q = searchQuery.toLowerCase();
    return (
      (t.account_name && t.account_name.toLowerCase().includes(q)) ||
      (t.description && t.description.toLowerCase().includes(q)) ||
      (t.raw_ai_input && t.raw_ai_input.toLowerCase().includes(q)) ||
      String(t.id).includes(q) ||
      String(t.amount).includes(q)
    );
  });

  return (
    <div className="w-full bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl space-y-6 text-slate-100">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-800">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="text-xl font-bold text-white">General Accounting Ledger</h3>
            <span className="text-xs px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-mono">
              {transactions.length} entries
            </span>
          </div>
          <p className="text-xs text-slate-400">Deterministic transaction log and natural language chat add-ons</p>
        </div>
        
        <div className="flex items-center gap-2 flex-wrap">
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search account, prompt..."
            className="bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-white placeholder-slate-500 outline-none focus:border-indigo-500 w-44"
          />
          <button
            onClick={fetchTransactions}
            className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200 transition-colors"
          >
            Refresh Ledger
          </button>
          {transactions.length > 0 && (
            <button
              onClick={handleClearAll}
              className="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 text-xs font-semibold transition-colors flex items-center gap-1"
            >
              <span>🗑️</span>
              <span>Clear All</span>
            </button>
          )}
        </div>
      </div>

      {successMsg && (
        <div className="p-3 bg-emerald-950/40 border border-emerald-500/40 text-emerald-300 rounded-xl text-xs flex items-center justify-between">
          <span>{successMsg}</span>
          <button onClick={() => setSuccessMsg(null)} className="text-emerald-400 font-bold hover:text-white">&times;</button>
        </div>
      )}

      {error && (
        <div className="p-4 bg-rose-950/40 border border-rose-500/40 text-rose-300 rounded-xl text-sm flex items-center justify-between">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-rose-400 font-bold hover:text-white">&times;</button>
        </div>
      )}

      {loading && (
        <div className="p-8 text-center text-slate-400 animate-pulse">
          Loading ledger entries...
        </div>
      )}

      {!loading && filteredTransactions.length === 0 && (
        <div className="p-12 text-center text-slate-500 text-sm">
          No transactions found.
        </div>
      )}

      {!loading && filteredTransactions.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-left text-xs text-slate-300">
            <thead className="bg-slate-950 text-slate-400 uppercase font-semibold">
              <tr>
                <th className="p-3">Tx ID</th>
                <th className="p-3">Date</th>
                <th className="p-3">Account</th>
                <th className="p-3">Description</th>
                <th className="p-3">Type</th>
                <th className="p-3">Amount</th>
                <th className="p-3">CGST / SGST</th>
                <th className="p-3">IGST</th>
                <th className="p-3">Status</th>
                <th className="p-3 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {filteredTransactions.map((tx) => (
                <tr key={tx.id} className="hover:bg-slate-800/40 transition-colors">
                  <td className="p-3 font-mono text-slate-500">
                    <div>#{tx.id}</div>
                    {tx.entry_group_id && (
                      <div className="text-[9px] text-indigo-400 font-mono mt-0.5" title={`Linked Entry Group: ${tx.entry_group_id}`}>
                        🔗 linked
                      </div>
                    )}
                  </td>
                  <td className="p-3 font-mono">{tx.date}</td>
                  <td className="p-3">
                    <div className="font-semibold text-white">{tx.account_name}</div>
                    <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">
                      {tx.account_type}
                    </span>
                  </td>
                  <td className="p-3 max-w-xs truncate text-slate-300">
                    {tx.description}
                    {tx.raw_ai_input && (
                      <div className="text-[10px] text-slate-500 italic truncate">
                        Prompt: "{tx.raw_ai_input}"
                      </div>
                    )}
                  </td>
                  <td className="p-3">
                    <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                      tx.type === 'credit'
                        ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                        : 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                    }`}>
                      {tx.type}
                    </span>
                  </td>
                  <td className="p-3 font-bold text-white font-mono">
                    ₹{Number(tx.amount).toLocaleString('en-IN')}
                  </td>
                  <td className="p-3 font-mono text-slate-400">
                    {tx.cgst > 0 || tx.sgst > 0 ? `₹${tx.cgst} / ₹${tx.sgst}` : '-'}
                  </td>
                  <td className="p-3 font-mono text-slate-400">
                    {tx.igst > 0 ? `₹${tx.igst}` : '-'}
                  </td>
                  <td className="p-3">
                    <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                      {tx.status}
                    </span>
                  </td>
                  <td className="p-3 text-right">
                    <button
                      type="button"
                      onClick={() => handleDelete(tx.id, tx.account_name, tx.amount)}
                      disabled={deletingId === tx.id}
                      className="px-2.5 py-1 text-xs rounded-lg bg-rose-500/10 hover:bg-rose-500/25 text-rose-400 border border-rose-500/30 transition flex items-center gap-1 font-semibold ml-auto disabled:opacity-50"
                      title="Delete this transaction"
                    >
                      {deletingId === tx.id ? (
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
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
