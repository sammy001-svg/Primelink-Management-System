'use client';

import Sidebar from '@/components/Sidebar';
import GlobalSearch from '@/components/GlobalSearch';
import NotificationCenter from '@/components/NotificationCenter';
import { supabase } from '@/lib/supabase';
import { useState, useEffect } from 'react';

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const [profile, setProfile] = useState<{ full_name: string; role: string } | null>(null);

  useEffect(() => {
    const fetchProfile = (user: any) => {
      if (user) {
        setProfile({
          full_name: user.user_metadata?.full_name || 'User',
          role: user.user_metadata?.role || 'tenant'
        });
      }
    };

    // Initial check
    supabase.auth.getSession().then(({ data: { session } }) => {
      fetchProfile(session?.user);
    });

    // Sub
    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, session) => {
      fetchProfile(session?.user);
    });

    return () => subscription?.unsubscribe();
  }, []);

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      <Sidebar />
      <div className="md:ml-64 p-8">
        <header className="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
          <div className="hidden md:block">
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">
              {profile?.role === 'tenant' ? 'Tenant Portal' : 'REMS Dashboard'}
            </h1>
            <p className="text-slate-600 dark:text-slate-400 font-medium">
              Welcome back, {profile?.full_name || 'Admin'}
            </p>
          </div>
          <div className="flex items-center gap-4 w-full md:w-auto">
            <GlobalSearch />
            <NotificationCenter />
            <div className="w-10 h-10 rounded-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center font-bold shadow-lg cursor-pointer shrink-0 uppercase">
              {profile?.full_name?.slice(0, 2) || 'AD'}
            </div>
          </div>
        </header>
        <main className="animate-in fade-in slide-in-from-bottom-4 duration-500">{children}</main>
      </div>
    </div>
  );
}
