'use client';

import Link from 'next/link';
import { 
  Building2, 
  CreditCard, 
  Wrench, 
  History,
  ArrowUpRight,
  Clock,
  MapPin,
  ChevronRight
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { supabase } from '@/lib/supabase';
import { mockRevenueData } from '@/lib/mock-data';
import PaymentModal from './PaymentModal';

export default function TenantDashboard() {
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [loading, setLoading] = useState(true);
  const [financials, setFinancials] = useState({
    rent: 0,
    balance: 0,
    activeRequests: 0
  });
  const [leaseInfo, setLeaseInfo] = useState<any>(null);
  const [recentPayments, setRecentPayments] = useState<any[]>([]);
  const [latestMaintenance, setLatestMaintenance] = useState<any>(null);

  useEffect(() => {
    async function fetchDashboardData() {
      setLoading(true);
      const { data: { session } } = await supabase.auth.getSession();
      const user = session?.user;
      if (!user) {
        setLoading(false);
        return;
      }

      // 1. Get Tenant ID
      const { data: tenantData } = await supabase
        .from('tenants')
        .select('id')
        .eq('user_id', user.id)
        .maybeSingle();

      if (tenantData) {
        const tenantId = tenantData.id;

        // 2. Get Active Lease
        const { data: activeLease } = await supabase
          .from('leases')
          .select(`
            monthly_rent, 
            status, 
            end_date,
            unit_id,
            units (
              unit_number,
              property_id,
              properties (
                title,
                location
              )
            )
          `)
          .eq('tenant_id', tenantId)
          .eq('status', 'Active')
          .maybeSingle();

        setLeaseInfo(activeLease);

        // 3. Get Outstanding Balance (Sum of Pending Transactions)
        const { data: pendingTxs } = await supabase
          .from('transactions')
          .select('amount')
          .eq('tenant_id', tenantId)
          .eq('status', 'Pending');
        
        const totalBalance = pendingTxs?.reduce((acc, tx) => acc + Number(tx.amount), 0) || 0;

        // 4. Get Active Maintenance Requests
        const { count } = await supabase
          .from('maintenance_requests')
          .select('*', { count: 'exact', head: true })
          .eq('tenant_id', tenantId)
          .neq('status', 'Completed');

        // 5. Get Recent Payments
        const { data: payments } = await supabase
          .from('transactions')
          .select('*')
          .eq('tenant_id', tenantId)
          .eq('status', 'Paid')
          .order('transaction_date', { ascending: false })
          .limit(3);
        
        setRecentPayments(payments || []);

        // 6. Get Latest Maintenance Request
        const { data: maintenance } = await supabase
          .from('maintenance_requests')
          .select('*')
          .eq('tenant_id', tenantId)
          .order('created_at', { ascending: false })
          .limit(1)
          .maybeSingle();

        setLatestMaintenance(maintenance);

        setFinancials({
          rent: activeLease?.monthly_rent || 0,
          balance: totalBalance,
          activeRequests: count || 0
        });
      }
      setLoading(false);
    }

    fetchDashboardData();
  }, []);

  const stats = [
    { label: 'Current Rent', value: `KSh ${financials.rent.toLocaleString()}`, icon: Building2, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/20' },
    { label: 'Outstanding Balance', value: `KSh ${financials.balance.toLocaleString()}`, icon: CreditCard, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/20' },
    { label: 'Active Requests', value: financials.activeRequests.toString(), icon: Wrench, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-900/20' },
  ];

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
      </div>
    );
  }

  return (
    <div className="space-y-8 animate-in fade-in duration-500">
      {/* Top Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        {stats.map((stat) => (
          <div key={stat.label} className="glass-card p-6 flex items-center gap-4 hover:translate-y-[-2px] transition-transform cursor-pointer group">
            <div className={`p-3 rounded-xl ${stat.bg} ${stat.color} group-hover:scale-110 transition-transform`}>
              <stat.icon size={24} />
            </div>
            <div>
              <p className="text-xs font-black text-slate-400 uppercase tracking-widest">{stat.label}</p>
              <h3 className="text-2xl font-black text-slate-900 dark:text-white">{stat.value}</h3>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
        <div className="xl:col-span-2 space-y-8">
          {/* My Unit Overview */}
          <div className="glass-card p-8 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 border-0 shadow-2xl overflow-hidden relative group">
            <div className="relative z-10">
              <div className="flex items-center gap-2 mb-4">
                <span className="px-2 py-1 bg-accent-gold/20 text-accent-gold rounded text-[10px] font-black uppercase tracking-widest">Active Lease</span>
                <span className="text-[10px] font-black opacity-60 uppercase tracking-widest">
                  {leaseInfo?.units?.properties?.title} - {leaseInfo?.units?.unit_number}
                </span>
              </div>
              <h3 className="text-2xl font-black mb-3 leading-tight">
                {financials.balance > 0 
                  ? `You have a pending balance of KSh ${financials.balance.toLocaleString()}`
                  : 'Your account is in good standing'}
              </h3>
              <p className="text-slate-400 dark:text-slate-500 text-sm font-medium leading-relaxed mb-6">
                {financials.balance > 0 
                  ? 'Please settle your outstanding bills to avoid penalties.'
                  : 'Thank you for being a valued tenant at Primelink.'}
              </p>
              <button 
                onClick={() => setShowPaymentModal(true)}
                className="px-8 py-3 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest hover:translate-y-[-2px] transition-all shadow-lg"
              >
                Pay Now
              </button>
            </div>
            <div className="absolute top-[-20%] right-[-10%] w-64 h-64 bg-accent-gold/20 rounded-full blur-3xl group-hover:scale-110 transition-transform duration-1000"></div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            {/* Recent Payments */}
            <div className="glass-card p-8">
              <div className="flex justify-between items-center mb-6">
                <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                  <History className="text-accent-gold" size={20} /> Recent Payments
                </h3>
              </div>
              <div className="space-y-4">
                {recentPayments.length > 0 ? (
                  recentPayments.map((payment, i) => (
                    <div key={i} className="flex justify-between items-center pb-4 border-b border-slate-100 dark:border-slate-800 last:border-0 last:pb-0 group cursor-pointer">
                      <div>
                        <p className="text-sm font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">
                          {payment.transaction_type} Payment
                        </p>
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                          {new Date(payment.transaction_date).toLocaleDateString()}
                        </p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-black text-slate-900 dark:text-white">KSh {payment.amount.toLocaleString()}</p>
                        <p className="text-[10px] font-black text-green-500 uppercase tracking-widest">{payment.status}</p>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="text-xs text-slate-400 italic">No recent payments found.</p>
                )}
              </div>
            </div>

            {/* Maintenance Status */}
            <div className="glass-card p-8">
              <h3 className="text-lg font-black text-slate-900 dark:text-white mb-6">Maintenance Requests</h3>
              <div className="space-y-4">
                {latestMaintenance ? (
                  <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 border-l-4 border-l-orange-500 shadow-sm transition-all hover:bg-white dark:hover:bg-slate-950">
                    <div className="flex justify-between items-start mb-2">
                      <span className={`px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter ${
                        latestMaintenance.status === 'Pending' ? 'bg-orange-100 text-orange-600' : 'bg-green-100 text-green-600'
                      }`}>
                        {latestMaintenance.status}
                      </span>
                      <span className="text-[10px] font-bold text-slate-400">
                        {new Date(latestMaintenance.created_at).toLocaleDateString()}
                      </span>
                    </div>
                    <p className="text-sm font-bold text-slate-900 dark:text-white mb-1">{latestMaintenance.title}</p>
                    <p className="text-[10px] font-medium text-slate-500 leading-tight line-clamp-2">
                      {latestMaintenance.description}
                    </p>
                  </div>
                ) : (
                  <p className="text-xs text-slate-400 italic mb-4">No active requests.</p>
                )}
                <Link 
                  href="/maintenance/tenant"
                  className="w-full py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-[10px] uppercase tracking-widest hover:opacity-90 active:scale-[0.98] transition-all flex items-center justify-center"
                >
                  Request Service
                </Link>
              </div>
            </div>
          </div>
        </div>

        {/* Home Details Sidebar */}
        <div className="space-y-6">
          <div className="glass-card p-8 bg-linear-to-b from-white to-slate-50 dark:from-slate-900 dark:to-slate-950">
            <h3 className="text-xl font-black text-slate-900 dark:text-white mb-6">Your Property</h3>
            <div className="rounded-2xl overflow-hidden mb-4 h-48 bg-slate-200 dark:bg-slate-800">
               {/* Image Placeholder */}
               <div className="w-full h-full flex items-center justify-center text-slate-400 font-black text-xs uppercase tracking-widest italic">Property View</div>
            </div>
            <h4 className="text-lg font-black text-slate-900 dark:text-white mb-2">
              {leaseInfo?.units?.properties?.title || 'Skyline Hub Apartments'}
            </h4>
            <div className="flex items-center gap-2 text-slate-500 mb-6 font-bold text-sm">
                <MapPin size={16} className="text-accent-gold" />
                {leaseInfo?.units?.properties?.location || 'Westlands, Nairobi'}
            </div>
            
            <div className="space-y-4 pt-4 border-t border-slate-100 dark:border-slate-800">
              <div className="flex justify-between text-xs font-bold">
                <span className="text-slate-400 uppercase tracking-widest">Unit Number</span>
                <span className="text-slate-900 dark:text-white">{leaseInfo?.units?.unit_number || 'N/A'}</span>
              </div>
              <div className="flex justify-between text-xs font-bold">
                <span className="text-slate-400 uppercase tracking-widest">Lease End</span>
                <span className="text-slate-900 dark:text-white">
                  {leaseInfo?.end_date ? new Date(leaseInfo.end_date).toLocaleDateString() : 'N/A'}
                </span>
              </div>
              <div className="flex justify-between text-xs font-bold">
                <span className="text-slate-400 uppercase tracking-widest">Type</span>
                <span className="text-slate-900 dark:text-white italic">Active Lease</span>
              </div>
            </div>
          </div>
          
          <div className="glass-card p-6 border-accent-gold/20 bg-accent-gold/5">
            <p className="text-xs font-bold text-slate-900 dark:text-white leading-relaxed text-center">
              Have any issues with your house? Contact your property manager directly.
            </p>
            <button className="w-full mt-4 py-3 bg-white dark:bg-slate-900 text-slate-900 dark:text-white border border-slate-200 dark:border-slate-800 rounded-xl font-black text-[10px] uppercase tracking-widest flex items-center justify-center gap-2 hover:bg-slate-50 transition-all">
              Message Manager
            </button>
          </div>
        </div>
      </div>
      <PaymentModal 
        isOpen={showPaymentModal} 
        onClose={() => setShowPaymentModal(false)}
        onSuccess={() => setShowPaymentModal(false)}
      />
    </div>
  );
}
