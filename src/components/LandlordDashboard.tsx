'use client';

import { 
  Building2, 
  Users, 
  BarChart3, 
  Wrench,
  TrendingUp,
  ArrowUpRight,
  Clock,
  MapPin,
  ChevronRight,
  History,
  X,
  FileText
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { supabase } from '@/lib/supabase';
import { mockRevenueData } from '@/lib/mock-data';

export default function LandlordDashboard() {
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState([
    { label: 'Total Properties', value: '0', icon: Building2, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/20' },
    { label: 'Active Tenants', value: '0', icon: Users, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/20' },
    { label: 'Revenue (MTD)', value: 'KSh 0', icon: BarChart3, color: 'text-accent-gold', bg: 'bg-amber-50 dark:bg-amber-900/20' },
    { label: 'Pending Maintenance', value: '0', icon: Wrench, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-900/20' },
  ]);
  const [recentActivity, setRecentActivity] = useState<any[]>([]);
  const [landlordData, setLandlordData] = useState<any>(null);
  const [showStatementModal, setShowStatementModal] = useState(false);
  const [statements, setStatements] = useState<any[]>([]);
  const [fetchingStatements, setFetchingStatements] = useState(false);

  useEffect(() => {
    async function fetchDashboardData() {
      setLoading(true);
      const { data: { session } } = await supabase.auth.getSession();
      const user = session?.user;
      if (!user) {
        setLoading(false);
        return;
      }

      // 1. Get Landlord ID
      const { data: lData } = await supabase
        .from('landlords')
        .select('id, full_name')
        .eq('user_id', user.id)
        .maybeSingle();

      if (lData) {
        setLandlordData(lData);
        const landlordId = lData.id;

        // 2. Fetch Stats
        // Total Properties
        const { count: propsCount } = await supabase
          .from('properties')
          .select('*', { count: 'exact', head: true })
          .eq('landlord_id', landlordId);

        // Active Tenants (via properties -> units -> leases)
        // This is a complex join, so we fetch properties first
        const { data: myProps } = await supabase
          .from('properties')
          .select('id')
          .eq('landlord_id', landlordId);
        
        const propIds = myProps?.map(p => p.id) || [];
        
        let tenantCount = 0;
        let revenueMTD = 0;
        let pendingMaint = 0;

        if (propIds.length > 0) {
          // Get Units
          const { data: myUnits } = await supabase
            .from('units')
            .select('id')
            .in('property_id', propIds);
          
          const unitIds = myUnits?.map(u => u.id) || [];

          if (unitIds.length > 0) {
            // Get Active Leases Count
            const { count: lCount } = await supabase
              .from('leases')
              .select('*', { count: 'exact', head: true })
              .in('unit_id', unitIds)
              .eq('status', 'Active');
            tenantCount = lCount || 0;

            // Revenue MTD (Paid transactions for these leases this month)
            const firstDayOfMonth = new Date();
            firstDayOfMonth.setDate(1);
            
            const { data: txs } = await supabase
              .from('transactions')
              .select('amount')
              .in('lease_id', (await supabase.from('leases').select('id').in('unit_id', unitIds)).data?.map(l => l.id) || [])
              .eq('status', 'Paid')
              .gte('transaction_date', firstDayOfMonth.toISOString().split('T')[0]);
            
            revenueMTD = txs?.reduce((acc, tx) => acc + Number(tx.amount), 0) || 0;
          }

          // Pending Maintenance
          const { count: mCount } = await supabase
            .from('maintenance_requests')
            .select('*', { count: 'exact', head: true })
            .in('property_id', propIds)
            .neq('status', 'Completed');
          pendingMaint = mCount || 0;
        }

        setStats([
          { label: 'Total Properties', value: (propsCount || 0).toString(), icon: Building2, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/20' },
          { label: 'Active Tenants', value: tenantCount.toString(), icon: Users, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/20' },
          { label: 'Revenue (MTD)', value: `KSh ${revenueMTD.toLocaleString()}`, icon: BarChart3, color: 'text-accent-gold', bg: 'bg-amber-50 dark:bg-amber-900/20' },
          { label: 'Pending Maintenance', value: pendingMaint.toString(), icon: Wrench, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-900/20' },
        ]);

        // 3. fetch Recent Activity
        const { data: activity } = await supabase
          .from('maintenance_requests')
          .select('title, created_at, status')
          .in('property_id', propIds)
          .order('created_at', { ascending: false })
          .limit(3);
        
        setRecentActivity(activity || []);
      }
      setLoading(false);
    }

    fetchDashboardData();
  }, []);
  
  const fetchStatements = async () => {
    try {
      if (!landlordData) return;
      setFetchingStatements(true);
      setShowStatementModal(true);
    
      // 1. Fetch Payouts
      const { data: payouts, error: pError } = await supabase
        .from('landlord_payouts')
        .select('*')
        .eq('landlord_id', landlordData.id)
        .order('payout_date', { ascending: false });
      
      if (pError) throw pError;

      // 2. Fetch Advances
      const { data: advances, error: aError } = await supabase
        .from('advance_requests')
        .select('*')
        .eq('landlord_id', landlordData.id)
        .order('created_at', { ascending: false });

      if (aError) throw aError;

      // 3. Normalize and Combine
      const normalizedPayouts = (payouts || []).map(p => ({
        id: p.id,
        date: p.payout_date,
        title: 'Monthly Payout',
        reference: p.reference_number || p.payment_method,
        amount: parseFloat(p.amount),
        status: p.status,
        type: 'Payout'
      }));

      const normalizedAdvances = (advances || []).map(a => ({
        id: a.id,
        date: a.created_at,
        title: 'Advance Request',
        reference: a.reason,
        amount: parseFloat(a.amount_requested),
        status: a.status,
        type: 'Advance'
      }));

      const combined = [...normalizedPayouts, ...normalizedAdvances].sort((a, b) => 
        new Date(b.date).getTime() - new Date(a.date).getTime()
      );
      
      setStatements(combined);
    } catch (err) {
      console.error('Error fetching statements:', err);
    } finally {
      setFetchingStatements(false);
    }
  };

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
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
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
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            {/* Recent Activity */}
            <div className="glass-card p-8">
              <div className="flex justify-between items-center mb-6">
                <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                  <History className="text-accent-gold" size={20} /> Property Activity
                </h3>
              </div>
              <div className="space-y-4">
                {recentActivity.length > 0 ? (
                  recentActivity.map((item, i) => (
                    <div key={i} className="flex gap-4 items-start pb-4 border-b border-slate-100 dark:border-slate-800 last:border-0 last:pb-0 group cursor-pointer">
                      <div className={`w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-accent-gold group-hover:bg-slate-900 group-hover:text-white transition-all`}>
                        <Wrench size={18} />
                      </div>
                      <div className="flex-1">
                        <p className="text-sm font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{item.title}</p>
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                          {item.status} • {new Date(item.created_at).toLocaleDateString()}
                        </p>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="text-xs text-slate-400 italic">No recent activity detected.</p>
                )}
              </div>
            </div>

            {/* Payout Summary */}
            <div className="glass-card p-8 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 border-0 shadow-2xl overflow-hidden relative group">
              <div className="relative z-10 h-full flex flex-col justify-between">
                <div>
                  <div className="flex items-center gap-2 mb-4">
                    <span className="px-2 py-1 bg-accent-gold/20 text-accent-gold rounded text-[10px] font-black uppercase tracking-widest">Payouts</span>
                  </div>
                  <h3 className="text-2xl font-black mb-3 leading-tight">Next Payout Scheduled</h3>
                  <p className="text-slate-400 dark:text-slate-500 text-sm font-medium leading-relaxed">
                    Your next automated distribution is set for the 1st of the coming month.
                  </p>
                </div>
                <button 
                  onClick={fetchStatements}
                  className="w-full mt-6 py-3 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest hover:translate-y-[-2px] transition-all shadow-lg"
                >
                  View Statements
                </button>
              </div>
              <div className="absolute top-[-20%] right-[-10%] w-64 h-64 bg-accent-gold/20 rounded-full blur-3xl group-hover:scale-110 transition-transform duration-1000"></div>
            </div>
          </div>
          
          {/* Statement Modal */}
          {showStatementModal && (
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
              <div className="glass-card w-full max-w-4xl bg-white dark:bg-slate-900 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-950/50">
                  <div>
                    <h3 className="text-xl font-bold text-slate-900 dark:text-white">Financial Statement</h3>
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-1">Portfolio Transaction History</p>
                  </div>
                  <button 
                    onClick={() => setShowStatementModal(false)}
                    className="p-2 transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white bg-white dark:bg-slate-800 rounded-xl shadow-sm"
                  >
                    <X size={20} />
                  </button>
                </div>

                <div className="p-8 overflow-y-auto custom-scrollbar flex-1">
                  {fetchingStatements ? (
                    <div className="py-20 text-center">
                       <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin mx-auto"></div>
                       <p className="text-slate-500 text-xs font-black uppercase tracking-widest mt-4">Generating Statement...</p>
                    </div>
                  ) : (
                    <div className="space-y-6">
                      <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                          <thead>
                            <tr className="border-b border-slate-100 dark:border-slate-800 text-[10px] font-black uppercase tracking-widest text-slate-400">
                              <th className="pb-4 px-2">Date</th>
                              <th className="pb-4 px-2">Transaction Type</th>
                              <th className="pb-4 px-2">Reference / Reason</th>
                              <th className="pb-4 px-2 text-right">Amount</th>
                              <th className="pb-4 px-2 text-center">Status</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-slate-50 dark:divide-slate-800/50">
                            {statements.length > 0 ? statements.map((st) => (
                              <tr key={st.id} className="text-sm">
                                <td className="py-4 px-2 font-bold text-slate-600 dark:text-slate-400">
                                  {new Date(st.date).toLocaleDateString()}
                                </td>
                                <td className="py-4 px-2">
                                   <p className="font-black text-slate-900 dark:text-white">{st.title}</p>
                                   <p className="text-[10px] text-accent-gold uppercase font-black tracking-widest">{st.type}</p>
                                </td>
                                <td className="py-4 px-2 font-bold text-slate-700 dark:text-slate-300">
                                  {st.reference || '--'}
                                </td>
                                <td className="py-4 px-2 text-right font-black text-slate-900 dark:text-white">
                                  KSh {st.amount.toLocaleString()}
                                </td>
                                <td className="py-4 px-2 text-center">
                                  <span className={`text-[9px] font-black uppercase px-2 py-1 rounded-md ${
                                    st.status === 'Paid' || st.status === 'Approved' ? 'bg-emerald-500/10 text-emerald-600' : 
                                    st.status === 'Pending' || st.status === 'Processing' ? 'bg-amber-500/10 text-amber-600' :
                                    'bg-red-500/10 text-red-600'
                                  }`}>
                                    {st.status}
                                  </span>
                                </td>
                              </tr>
                            )) : (
                              <tr>
                                <td colSpan={6} className="py-20 text-center text-slate-400 italic text-sm">
                                  No transaction records found for this portfolio.
                                </td>
                              </tr>
                            )}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                </div>

                <div className="p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 flex justify-between items-center">
                  <div className="text-left">
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Settled (Payouts)</p>
                    <p className="text-xl font-black text-slate-900 dark:text-white">
                      KSh {statements
                        .filter(st => st.type === 'Payout' && st.status === 'Paid')
                        .reduce((acc, st) => acc + Number(st.amount), 0)
                        .toLocaleString()}
                    </p>
                  </div>
                  <button className="px-8 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:scale-105 transition-transform flex items-center gap-2">
                    <FileText size={16} /> Export PDF
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Occupancy Chart Placeholder */}
          <div className="glass-card p-8">
            <div className="flex justify-between items-center mb-10">
              <div>
                <h3 className="text-lg font-black text-slate-900 dark:text-white">Occupancy Trends</h3>
                <p className="text-xs text-slate-500 font-black uppercase tracking-widest">Real-time portfolio status</p>
              </div>
              <div className="text-right">
                <span className="text-2xl font-black text-green-500 flex items-center gap-1 justify-end">
                  <ArrowUpRight size={20} /> 100%
                </span>
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Occupancy</p>
              </div>
            </div>
            <div className="h-48 flex items-end justify-between gap-4">
              {mockRevenueData.map((data, idx) => (
                <div key={idx} className="flex-1 flex flex-col items-center gap-3 group h-full">
                  <div className="flex-1 w-full flex items-end">
                    <div 
                      className="w-full bg-slate-100 dark:bg-slate-800 rounded-xl transition-all group-hover:bg-accent-gold" 
                      style={{ height: `${(data.amount / 60000) * 100}%` }}
                    ></div>
                  </div>
                  <span className="text-[10px] font-black text-slate-400 uppercase opacity-0 group-hover:opacity-100 transition-opacity">{data.month}</span>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
           <div className="glass-card p-8 bg-linear-to-b from-white to-slate-50 dark:from-slate-900 dark:to-slate-950">
             <h3 className="text-xl font-black text-slate-900 dark:text-white mb-6">Quick Stats</h3>
             <div className="space-y-6">
                <div>
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Portfolio Value</p>
                   <p className="text-2xl font-black text-slate-900 dark:text-white">Estimate Only</p>
                </div>
                <div className="pt-6 border-t border-slate-100 dark:border-slate-800">
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Average Rent</p>
                   <p className="text-xl font-black text-slate-900 dark:text-white">KSh {stats[2].value.split(' ')[1]}</p>
                </div>
             </div>
           </div>

           <div className="glass-card p-6 border-accent-gold/20 bg-accent-gold/5">
              <p className="text-xs font-bold text-slate-900 dark:text-white leading-relaxed text-center">
                Need detailed investment reports or portfolio optimization advice?
              </p>
              <button className="w-full mt-4 py-3 bg-white dark:bg-slate-900 text-slate-900 dark:text-white border border-slate-200 dark:border-slate-800 rounded-xl font-black text-[10px] uppercase tracking-widest flex items-center justify-center gap-2 hover:bg-slate-50 transition-all">
                Contact Advisory
              </button>
           </div>
        </div>
      </div>
    </div>
  );
}
