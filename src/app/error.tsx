'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { RefreshCcw, Home, ShieldAlert } from 'lucide-react';

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-6 text-center">
      <div className="glass-card max-w-lg w-full p-12 space-y-8 animate-in fade-in duration-500">
        <div className="p-4 bg-red-500/10 rounded-2xl w-20 h-20 mx-auto flex items-center justify-center">
          <ShieldAlert size={40} className="text-red-500" />
        </div>

        <div className="space-y-4">
          <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">System Interruption</h1>
          <p className="text-slate-500 font-medium">
            Something went wrong while processing your request. Our team has been notified.
          </p>
          <div className="p-4 bg-slate-900/5 dark:bg-white/5 rounded-xl border border-slate-100 dark:border-slate-800">
            <code className="text-[10px] font-bold text-slate-400 break-all">
              {error.message || 'An unexpected runtime error occurred.'}
            </code>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row gap-4 pt-4">
          <button
            onClick={() => reset()}
            className="flex-1 flex items-center justify-center gap-2 px-6 py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold hover:opacity-90 transition-opacity"
          >
            <RefreshCcw size={18} /> Try Again
          </button>
          <Link
            href="/"
            className="flex-1 flex items-center justify-center gap-2 px-6 py-4 border border-slate-200 dark:border-slate-800 rounded-xl font-bold text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-900 transition-colors"
          >
            <Home size={18} /> Back to Safety
          </Link>
        </div>
      </div>
    </div>
  );
}
