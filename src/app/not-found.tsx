'use client';

import Link from 'next/link';
import { Home, Search, AlertCircle, ChevronLeft } from 'lucide-react';

export default function NotFound() {
  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-6">
      <div className="glass-card max-w-lg w-full p-12 text-center space-y-8 animate-in zoom-in-95 duration-500">
        <div className="relative mx-auto w-24 h-24">
          <div className="absolute inset-0 bg-accent-gold/20 rounded-full animate-ping"></div>
          <div className="relative bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-full w-24 h-24 flex items-center justify-center shadow-xl">
            <AlertCircle size={48} className="text-accent-gold" />
          </div>
        </div>

        <div className="space-y-4">
          <h1 className="text-6xl font-black text-slate-900 dark:text-white tracking-tighter">404</h1>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-200">Lost in Transit?</h2>
          <p className="text-slate-500 font-medium">
            We couldn't find the page you're looking for. It might have been moved, deleted, or never existed in this dimension.
          </p>
        </div>

        <div className="flex flex-col sm:flex-row gap-4 pt-4">
          <Link 
            href="/"
            className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold hover:opacity-90 transition-opacity"
          >
            <Home size={18} /> Back to Home
          </Link>
          <Link 
            href="/properties"
            className="flex-1 flex items-center justify-center gap-2 px-6 py-3 border border-slate-200 dark:border-slate-800 rounded-xl font-bold text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-900 transition-colors"
          >
            <Search size={18} /> Search Properties
          </Link>
        </div>

        <button 
          onClick={() => window.history.back()}
          className="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center justify-center gap-2 mx-auto hover:text-slate-900 dark:hover:text-white transition-colors"
        >
          <ChevronLeft size={14} /> Go Back Previous
        </button>
      </div>
    </div>
  );
}
