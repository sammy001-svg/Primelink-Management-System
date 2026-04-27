'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { 
  BarChart3, 
  Building2, 
  Users, 
  FileText, 
  Settings, 
  LogOut, 
  Wrench, 
  CreditCard,
  LayoutDashboard,
  Package,
  Coins,
  Briefcase,
  HeartHandshake,
  Wallet,
  CalendarClock,
  UserPlus,
  HandCoins
} from 'lucide-react';

import { supabase } from '@/lib/supabase';
import { useState, useEffect } from 'react';

const generalMenuItems = [
  { icon: LayoutDashboard, label: 'Dashboard', href: '/', roles: ['admin', 'staff', 'tenant', 'landlord'] },
  { icon: Package, label: 'Inventory', href: '/inventory', roles: ['admin', 'staff'] },
  { icon: Building2, label: 'Properties', href: '/properties', roles: ['admin', 'staff', 'landlord'] },
  { icon: Users, label: 'Tenants', href: '/tenants', roles: ['admin', 'staff', 'landlord'] },
  { icon: Coins, label: 'Tokens', href: '/tokens', roles: ['admin', 'staff'] },
  { icon: Briefcase, label: 'Landlords', href: '/landlords', roles: ['admin', 'staff'] },
  { icon: FileText, label: 'Leases', href: '/leases', roles: ['admin', 'staff', 'tenant'] },
  { icon: CreditCard, label: 'Financials', href: '/financials', roles: ['admin', 'staff', 'tenant', 'landlord'] },
  { icon: Wrench, label: 'Maintenance', href: '/maintenance', roles: ['admin', 'staff', 'tenant', 'landlord'] },
  { icon: FileText, label: 'Documents', href: '/documents', roles: ['admin', 'staff', 'tenant'] },
];

const hrMenuItems = [
  { icon: Users, label: 'Employees', href: '/hr/employees', roles: ['admin', 'staff'] },
  { icon: Wallet, label: 'Payroll', href: '/hr/payroll', roles: ['admin'] },
  { icon: CalendarClock, label: 'Leaves', href: '/hr/leaves', roles: ['admin', 'staff'] },
  { icon: HandCoins, label: 'Advances', href: '/hr/advances', roles: ['admin', 'staff'] },
  { icon: UserPlus, label: 'Recruitment', href: '/hr/recruitment', roles: ['admin'] },
];

const reportMenuItems = [
  { icon: BarChart3, label: 'General Reports', href: '/reports/general', roles: ['admin', 'staff'] },
  { icon: HeartHandshake, label: 'HR Reports', href: '/reports/hr', roles: ['admin'] },
  { icon: Package, label: 'Inventory Reports', href: '/reports/inventory', roles: ['admin', 'staff'] },
  { icon: CreditCard, label: 'Financial Reports', href: '/reports/financial', roles: ['admin', 'staff'] },
];

export default function Sidebar() {
  const pathname = usePathname();
  const [role, setRole] = useState<string | null>(null);

  useEffect(() => {
    // Initial session check
    supabase.auth.getSession().then(({ data: { session } }) => {
      if (session?.user) {
        setRole(session.user.user_metadata?.role || 'tenant');
      }
    });

    // Listen for changes
    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, session) => {
      if (session?.user) {
        setRole(session.user.user_metadata?.role || 'tenant');
      } else {
        setRole(null);
      }
    });

    return () => {
      subscription?.unsubscribe();
    };
  }, []);

  const isVisible = (itemRoles?: string[]) => {
    if (!itemRoles) return true;
    if (!role) return false;
    // Admin role (manual or staff) usually sees all, but let's be role specific
    const userRole = role.toLowerCase();
    // Map staff/utility to staff for simple RBAC if needed, but let's just check inclusion
    return itemRoles.includes(userRole) || userRole === 'staff';
  };

  const renderMenuItem = (item: any) => {
    let href = item.href;
    if (item.label === 'Maintenance' && role === 'tenant') href = '/maintenance/tenant';
    if (item.label === 'Documents' && role === 'tenant') href = '/documents/tenant';
    
    const isActive = pathname === href || (href !== '/' && pathname?.startsWith(href));
    
    return (
      <Link
        key={item.label}
        href={href}
        className={`flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 ${
          isActive 
            ? 'bg-accent-gold text-white shadow-lg shadow-accent-gold/20' 
            : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'
        }`}
      >
        <item.icon size={20} className={isActive ? 'text-white' : ''} />
        <span className="font-bold">{item.label}</span>
      </Link>
    );
  };

  return (
    <aside className="w-64 h-screen fixed left-0 top-0 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 hidden md:flex flex-col">
      <div className="p-6 border-b border-slate-200 dark:border-slate-800">
        <h2 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
          <div className="w-8 h-8 bg-accent-gold rounded-lg shadow-lg flex items-center justify-center text-white">
            P
          </div>
          PrimeLink
        </h2>
      </div>

      <nav className="flex-1 p-4 space-y-2 overflow-y-auto custom-scrollbar">
        <div className="space-y-1">
          {generalMenuItems.filter(i => isVisible(i.roles)).map(renderMenuItem)}
        </div>

        {(role === 'admin' || role === 'staff') && (
          <>
            <div className="pt-4 pb-2">
              <p className="px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">
                Human Resource
              </p>
            </div>

            <div className="space-y-1">
              {hrMenuItems.filter(i => isVisible(i.roles)).map(renderMenuItem)}
            </div>

            <div className="pt-4 pb-2">
              <p className="px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">
                Analytics & Reports
              </p>
            </div>

            <div className="space-y-1">
              {reportMenuItems.filter(i => isVisible(i.roles)).map(renderMenuItem)}
            </div>
          </>
        )}
      </nav>

      <div className="p-4 border-t border-slate-200 dark:border-slate-800 space-y-2">
        <Link
          href="/settings"
          className={`flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 ${
            pathname === '/settings'
              ? 'bg-accent-gold text-white shadow-lg shadow-accent-gold/20'
              : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all duration-200'
          }`}
        >
          <Settings size={20} />
          <span className="font-bold">Settings</span>
        </Link>
        <button 
          onClick={() => {
            // Mock sign out logic
            window.location.href = '/login';
          }}
          className="flex items-center gap-3 px-4 py-3 text-red-500 dark:text-red-400 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 w-full transition-all duration-200 font-bold"
        >
          <LogOut size={20} />
          <span>Sign Out</span>
        </button>
      </div>
    </aside>
  );
}
