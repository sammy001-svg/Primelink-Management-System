'use client';

import { useState, useEffect } from 'react';
import { Search, X, Building2, Users, Wrench, Command } from 'lucide-react';
import { mockProperties, mockTenants, mockMaintenanceRequests } from '@/lib/mock-data';
import Link from 'next/link';

export default function GlobalSearch() {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<{
    properties: any[];
    tenants: any[];
    requests: any[];
  }>({ properties: [], tenants: [], requests: [] });

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setIsOpen(true);
      }
      if (e.key === 'Escape') setIsOpen(false);
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  useEffect(() => {
    if (query.length < 2) {
      setResults({ properties: [], tenants: [], requests: [] });
      return;
    }

    const q = query.toLowerCase();
    setResults({
      properties: mockProperties.filter(p => p.title.toLowerCase().includes(q) || p.location.toLowerCase().includes(q)),
      tenants: mockTenants.filter(t => t.name.toLowerCase().includes(q) || t.email.toLowerCase().includes(q)),
      requests: mockMaintenanceRequests.filter(r => r.issue.toLowerCase().includes(q) || r.propertyName.toLowerCase().includes(q))
    });
  }, [query]);

  if (!isOpen) {
    return (
      <button 
        onClick={() => setIsOpen(true)}
        className="flex items-center gap-3 px-4 py-2 bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-500 hover:border-accent-gold transition-all w-full max-w-md group"
      >
        <Search size={18} className="group-hover:text-accent-gold transition-colors" />
        <span className="text-sm font-medium">Search anything...</span>
        <div className="ml-auto flex items-center gap-1 px-1.5 py-0.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-[10px] font-black uppercase">
          <Command size={10} /> K
        </div>
      </button>
    );
  }

  return (
    <div className="fixed inset-0 z-100 flex items-start justify-center p-4 sm:p-24 bg-slate-950/40 backdrop-blur-md animate-in fade-in duration-300">
      <div className="glass-card w-full max-w-2xl bg-white dark:bg-slate-900 shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
        <div className="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center gap-4">
          <Search className="text-accent-gold" size={20} />
          <input 
            autoFocus
            type="text" 
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search properties, tenants, or maintenance..."
            className="flex-1 bg-transparent text-lg font-bold text-slate-900 dark:text-white outline-none placeholder:text-slate-400"
          />
          <button onClick={() => setIsOpen(false)} className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors">
            <X size={20} />
          </button>
        </div>

        <div className="max-h-[60vh] overflow-y-auto p-4 custom-scrollbar">
          {query.length === 0 ? (
            <div className="py-12 text-center space-y-2">
              <Search className="mx-auto text-slate-200 dark:text-slate-800" size={48} />
              <p className="text-sm font-bold text-slate-400">Type something to search...</p>
            </div>
          ) : (
            <div className="space-y-6">
              {results.properties.length > 0 && (
                <div>
                  <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <Building2 size={12} /> Properties
                  </h3>
                  <div className="space-y-2">
                    {results.properties.map(p => (
                      <Link key={p.id} href="/properties" onClick={() => setIsOpen(false)} className="flex items-center gap-4 p-3 rounded-2xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                        <div className="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-gold">
                          <Building2 size={20} />
                        </div>
                        <div>
                          <p className="font-bold text-slate-900 dark:text-white">{p.title}</p>
                          <p className="text-xs text-slate-500">{p.location}</p>
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {results.tenants.length > 0 && (
                <div>
                  <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <Users size={12} /> Tenants
                  </h3>
                  <div className="space-y-2">
                    {results.tenants.map(t => (
                      <Link key={t.id} href="/tenants" onClick={() => setIsOpen(false)} className="flex items-center gap-4 p-3 rounded-2xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                        <div className="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-gold">
                          <Users size={20} />
                        </div>
                        <div>
                          <p className="font-bold text-slate-900 dark:text-white">{t.name}</p>
                          <p className="text-xs text-slate-500">{t.email}</p>
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {results.requests.length > 0 && (
                <div>
                  <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <Wrench size={12} /> Maintenance
                  </h3>
                  <div className="space-y-2">
                    {results.requests.map(r => (
                      <Link key={r.id} href="/maintenance" onClick={() => setIsOpen(false)} className="flex items-center gap-4 p-3 rounded-2xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                        <div className="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-gold">
                          <Wrench size={20} />
                        </div>
                        <div>
                          <p className="font-bold text-slate-900 dark:text-white">{r.issue}</p>
                          <p className="text-xs text-slate-500">{r.propertyName}</p>
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {results.properties.length === 0 && results.tenants.length === 0 && results.requests.length === 0 && (
                <div className="py-12 text-center text-slate-400">
                  <p className="text-sm font-bold">No results found for "{query}"</p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
