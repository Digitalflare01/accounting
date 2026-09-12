import React, { useState, useEffect } from 'react';
import TransactionEntry from './components/TransactionEntry';
import ReportsView from './components/ReportsView';
import LedgerView from './components/LedgerView';
import AIDepreciationAdvisor from './components/AIDepreciationAdvisor';
import InvoicingView from './components/InvoicingView';
import BusinessProfileModal from './components/BusinessProfileModal';

export default function App() {
  const [token, setToken] = useState(localStorage.getItem('accounting_token') || '');
  const [user, setUser] = useState(null);
  const [activeTab, setActiveTab] = useState('entry'); // 'entry' | 'invoicing' | 'depreciation' | 'reports' | 'ledger'
  const [refreshTrigger, setRefreshTrigger] = useState(0);
  const [showProfileModal, setShowProfileModal] = useState(false);

  // Login Form States
  const [email, setEmail] = useState('admin@accounting.local');
  const [password, setPassword] = useState('Password123!');
  const [loginError, setLoginError] = useState(null);
  const [isLoggingIn, setIsLoggingIn] = useState(false);

  // Dynamic API Base URL - works seamlessly with both WAMP (/accounting) and root hosts
  const getApiBase = () => {
    const path = typeof window !== 'undefined' ? window.location.pathname : '';
    if (path.includes('/accounting')) {
      return '/accounting/api';
    }
    return '/api';
  };
  const apiUrl = getApiBase();

  // Auto-login on mount with default seed credentials if no token
  useEffect(() => {
    if (!token) {
      performLogin('admin@accounting.local', 'Password123!');
    } else {
      fetchUser(token);
    }
  }, []);

  const performLogin = async (loginEmail, loginPass) => {
    setIsLoggingIn(true);
    setLoginError(null);
    try {
      const res = await fetch(`${apiUrl}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: loginEmail, password: loginPass })
      });
      const contentType = res.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        throw new Error(`API server returned non-JSON response (${res.status} ${res.statusText}). Please ensure PHP backend and .htaccess are deployed.`);
      }
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Authentication failed');
      }
      setToken(data.token);
      setUser(data.user);
      localStorage.setItem('accounting_token', data.token);
    } catch (err) {
      setLoginError(err.message);
    } finally {
      setIsLoggingIn(false);
    }
  };

  const fetchUser = async (authToken) => {
    try {
      const res = await fetch(`${apiUrl}/auth/me`, {
        headers: { 'Authorization': `Bearer ${authToken}` }
      });
      const data = await res.json();
      if (res.ok && data.success) {
        setUser(data.user);
      } else {
        localStorage.removeItem('accounting_token');
        setToken('');
      }
    } catch (e) {
      // ignore
    }
  };

  const handleTransactionSaved = () => {
    setRefreshTrigger((prev) => prev + 1);
  };

  const handleLogout = () => {
    setToken('');
    setUser(null);
    localStorage.removeItem('accounting_token');
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
      {/* Top Navbar */}
      <header className="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-50">
        <div className="w-full px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30 text-white font-black text-xl">
              Σ
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-extrabold text-lg tracking-tight text-white">ApexLedger AI</span>
                <span className="text-[10px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-semibold border border-indigo-500/30">
                  Dual-Engine (PHP + Python)
                </span>
              </div>
              <p className="text-xs text-slate-400">
                AI Accounting & Indian Tax Preparation System
              </p>
            </div>
          </div>

          {token && user && (
            <div className="flex items-center gap-3">
              <button
                onClick={() => setShowProfileModal(true)}
                className="text-xs px-3 py-1.5 rounded-lg border border-indigo-500/40 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 font-bold transition flex items-center gap-1.5"
              >
                <span>🏢 Profile & Logo</span>
              </button>

              <div className="hidden sm:block text-right">
                <div className="text-xs font-semibold text-white">{user.business_name || user.email}</div>
                <div className="text-[11px] font-mono text-slate-400">
                  GSTIN: <span className="text-indigo-400 font-bold">{user.gst_number || '27ABCDE1234F1Z5'}</span>
                </div>
              </div>
              <button
                onClick={handleLogout}
                className="text-xs px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800/80 hover:bg-slate-700 text-slate-300 transition-colors"
              >
                Logout
              </button>
            </div>
          )}
        </div>
      </header>

      {/* Main Container */}
      <main className="flex-1 w-full px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        {!token ? (
          /* Login Box */
          <div className="max-w-md mx-auto bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl space-y-6">
            <div className="text-center">
              <h2 className="text-2xl font-bold text-white">Sign In to ApexLedger</h2>
              <p className="text-xs text-slate-400 mt-1">Authenticate using enterprise accounting credentials</p>
            </div>

            {loginError && (
              <div className="p-3 bg-rose-950/40 border border-rose-500/40 text-rose-300 rounded-lg text-xs">
                {loginError}
              </div>
            )}

            <form onSubmit={(e) => { e.preventDefault(); performLogin(email, password); }} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Email</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  required
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Password</label>
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 outline-none"
                  required
                />
              </div>
              <button
                type="submit"
                disabled={isLoggingIn}
                className="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2.5 rounded-xl shadow-lg shadow-indigo-600/25 transition-all text-sm"
              >
                {isLoggingIn ? 'Authenticating...' : 'Sign In'}
              </button>
            </form>
          </div>
        ) : (
          /* Authenticated Dashboard */
          <>
            {/* Nav Tabs */}
            <div className="flex flex-wrap items-center justify-center sm:justify-start gap-2 border-b border-slate-800 pb-4">
              <button
                onClick={() => setActiveTab('entry')}
                className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all ${
                  activeTab === 'entry'
                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                }`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span>AI Ingestion & Disambiguation</span>
              </button>

              <button
                onClick={() => setActiveTab('invoicing')}
                className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all ${
                  activeTab === 'invoicing'
                    ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-lg shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                }`}
              >
                <span>🧾 Invoicing & Billing</span>
              </button>

              <button
                onClick={() => setActiveTab('depreciation')}
                className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all ${
                  activeTab === 'depreciation'
                    ? 'bg-teal-600 text-white shadow-lg shadow-teal-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                }`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
                <span>AI Depreciation Advisor</span>
              </button>

              <button
                onClick={() => setActiveTab('reports')}
                className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all ${
                  activeTab === 'reports'
                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                }`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Financial Reports & Principles</span>
              </button>

              <button
                onClick={() => setActiveTab('ledger')}
                className={`flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold transition-all ${
                  activeTab === 'ledger'
                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-900'
                }`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                <span>General Ledger</span>
              </button>
            </div>

            {/* Tab Views */}
            {activeTab === 'entry' && (
              <TransactionEntry
                token={token}
                apiUrl={apiUrl}
                onTransactionSaved={handleTransactionSaved}
              />
            )}

            {activeTab === 'invoicing' && (
              <InvoicingView
                token={token}
                user={user}
                apiUrl={apiUrl}
                onInvoicePosted={handleTransactionSaved}
                onOpenProfile={() => setShowProfileModal(true)}
              />
            )}

            {activeTab === 'depreciation' && (
              <AIDepreciationAdvisor
                token={token}
                apiUrl={apiUrl}
                onDepreciationPosted={handleTransactionSaved}
              />
            )}

            {activeTab === 'reports' && (
              <ReportsView
                token={token}
                apiUrl={apiUrl}
              />
            )}

            {activeTab === 'ledger' && (
              <LedgerView
                token={token}
                apiUrl={apiUrl}
                refreshTrigger={refreshTrigger}
              />
            )}
          </>
        )}
      </main>

      {/* Business Profile Modal */}
      <BusinessProfileModal
        isOpen={showProfileModal}
        onClose={() => setShowProfileModal(false)}
        token={token}
        user={user}
        apiUrl={apiUrl}
        onProfileUpdated={(updated) => setUser(updated)}
      />

      {/* Footer */}
      <footer className="border-t border-slate-800/80 bg-slate-950 py-6 text-center text-xs text-slate-500">
        ApexLedger Hybrid AI Architecture &bull; Core Backend: PHP 8.3 REST &bull; AI Service: Python FastAPI (Google Gemini API) &bull; Frontend: React.js & Tailwind CSS
      </footer>
    </div>
  );
}
