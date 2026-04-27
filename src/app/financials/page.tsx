'use client';

import { useState, useEffect } from 'react';
import { supabase } from '@/lib/supabase';
import DashboardLayout from '@/components/DashboardLayout';
import PaymentModal from '@/components/PaymentModal';
import { mockTenants } from '@/lib/mock-data';
import { 
  DollarSign, 
  ArrowUpRight, 
  ArrowDownRight, 
  Download, 
  Calendar,
  Wallet,
  Droplets,
  Zap,
  History,
  CreditCard,
  Plus,
  Receipt,
  FileDown
} from 'lucide-react';

export default function FinancialsPage() {
  const [profile, setProfile] = useState<any>(null);
  const [tenant, setTenant] = useState<any>(null);
  const [landlord, setLandlord] = useState<any>(null);
  const [lease, setLease] = useState<any>(null);
  const [role, setRole] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [totalPaid, setTotalPaid] = useState(0);
  const [balance, setBalance] = useState(0); 
  const [waterBill, setWaterBill] = useState(0);
  const [showTokenModal, setShowTokenModal] = useState(false);
  const [tokenStep, setTokenStep] = useState(1);
  const [tokenType, setTokenType] = useState<'Electricity' | 'Water' | null>(null);
  const [tokenNumber, setTokenNumber] = useState('');
  const [tokenAmount, setTokenAmount] = useState('');
  const [generatedToken, setGeneratedToken] = useState<string | null>(null);
  const [paymentMethod, setPaymentMethod] = useState<string | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);
  const [showReceiptModal, setShowReceiptModal] = useState(false);
  const [selectedTx, setSelectedTx] = useState<any>(null);
  const [txHistory, setTxHistory] = useState<any[]>([]);
  const [advanceRequests, setAdvanceRequests] = useState<any[]>([]);
  const [showAdvanceModal, setShowAdvanceModal] = useState(false);
  const [advanceAmount, setAdvanceAmount] = useState('');
  const [advanceReason, setAdvanceReason] = useState('');
  const [requestStatus, setRequestStatus] = useState<'idle' | 'loading' | 'success'>('idle');

  useEffect(() => {
    async function initData() {
      setLoading(true);
      const { data: { session } } = await supabase.auth.getSession();
      const user = session?.user;
      
      if (user) {
        // 1. Get Profile
        const { data: profileData } = await supabase
          .from('profiles')
          .select('*')
          .eq('id', user.id)
          .single();
        
        setProfile(profileData);
        setRole(profileData?.role || 'tenant');

        let tenantId = null;

        // 2. Get Tenant Info if applicable
        if (profileData?.role === 'tenant') {
          const { data: tenantData } = await supabase
            .from('tenants')
            .select('*')
            .eq('user_id', user.id)
            .maybeSingle();
          
          setTenant(tenantData);
          tenantId = tenantData?.id;

          if (tenantData) {
            const { data: leaseData } = await supabase
              .from('leases')
              .select('*')
              .eq('tenant_id', tenantData.id)
              .eq('status', 'Active')
              .maybeSingle();
            
            setLease(leaseData);
          }
        } else if (profileData?.role === 'landlord') {
          const { data: landlordData } = await supabase
            .from('landlords')
            .select('*')
            .eq('user_id', user.id)
            .maybeSingle();
          
          setLandlord(landlordData);
        }

        await fetchTransactions(profileData, tenantId);
      }
      setLoading(false);
    }
    initData();
  }, []);

  async function fetchTransactions(currentProfile: any, tenantId: string | null) {
    try {
      let query = supabase.from('transactions').select(`
        *,
        tenants (
          full_name,
          leases (
            units (
              unit_number,
              properties (title)
            )
          )
        )
      `);

      if (currentProfile?.role === 'tenant' && tenantId) {
        query = query.eq('tenant_id', tenantId);
        const { data, error } = await query.order('created_at', { ascending: false });
        if (error) throw error;
        
        const formatted = (data || []).map(tx => ({
          id: tx.id,
          date: tx.transaction_date || tx.created_at,
          tenant: tx.tenants?.full_name || 'System',
          property: tx.tenants?.leases?.[0]?.units?.properties?.title || 'Main Property',
          amount: parseFloat(tx.amount) || 0,
          status: tx.status,
          method: tx.payment_method || 'Standard',
          type: tx.transaction_type
        }));
        setTxHistory(formatted);
      } else if (currentProfile?.role === 'landlord') {
        // 1. Fetch Payouts for the table
        const { data: payouts, error: pError } = await supabase
          .from('landlord_payouts')
          .select('*')
          .order('payout_date', { ascending: false });
        
        if (pError) throw pError;

        const formattedPayouts = (payouts || []).map(p => ({
          id: p.id,
          date: p.payout_date,
          tenant: p.reference_number || 'Payout',
          property: p.payment_method || 'Transfer',
          amount: parseFloat(p.amount) || 0,
          status: p.status,
          method: p.payment_method,
          type: 'Payout'
        }));
        setTxHistory(formattedPayouts);

        // 2. Fetch all transactions to calculate aggregates (RLS handles filtering)
        const { data: allTxs } = await supabase.from('transactions').select('amount, status, transaction_type, created_at');
        
        const now = new Date();
        const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);

        const monthlyRevenue = (allTxs || [])
          .filter(tx => tx.status === 'Paid' && new Date(tx.created_at) >= startOfMonth)
          .reduce((acc, curr) => acc + (parseFloat(curr.amount) || 0), 0);
        
        const monthlyOutstanding = (allTxs || [])
          .filter(tx => tx.status === 'Pending' && new Date(tx.created_at) >= startOfMonth)
          .reduce((acc, curr) => acc + (parseFloat(curr.amount) || 0), 0);

        setTotalPaid(monthlyRevenue);
        setBalance(monthlyOutstanding);
        setWaterBill(0); 

        // 3. Fetch Advance Requests
        const { data: advances } = await supabase
          .from('advance_requests')
          .select('*')
          .order('created_at', { ascending: false });
        
        setAdvanceRequests(advances || []);
      } else {
        // Staff/Admin view (all transactions)
        const { data, error } = await query.order('created_at', { ascending: false });
        if (error) throw error;
        setTxHistory(data || []);
      }
    } catch (err: any) {
      console.error('Error fetching transactions:', err.message || err.details || err);
    }
  }

  const handlePaymentSuccess = async (details: { amount: number, method: string, type: string }) => {
    if (!tenant) return;

    try {
      const { error } = await supabase.from('transactions').insert({
        tenant_id: tenant.id,
        lease_id: lease?.id,
        amount: details.amount,
        transaction_type: details.type === 'Both' ? 'Rent' : details.type,
        status: 'Paid',
        payment_method: details.method
      });

      if (error) throw error;
      
      await fetchTransactions(profile, tenant.id);
      setShowPaymentModal(false);
    } catch (err: any) {
      alert('Error saving payment: ' + err.message);
    }
  };

  const handleBuyTokens = async () => {
    if (!tenant) return;
    setIsProcessing(true);
    
    try {
      // Simulate validation
      await new Promise(r => setTimeout(r, 1500));

      const token = Math.floor(1000 + Math.random() * 9000).toString() + "-" + 
                  Math.floor(1000 + Math.random() * 9000).toString() + "-" +
                  Math.floor(1000 + Math.random() * 9000).toString() + "-" +
                  Math.floor(1000 + Math.random() * 9000).toString();
      
      const { error } = await supabase.from('transactions').insert({
        tenant_id: tenant.id,
        lease_id: lease?.id,
        amount: parseFloat(tokenAmount) || 0,
        transaction_type: `${tokenType} Token`,
        status: 'Paid',
        payment_method: paymentMethod || 'Standard'
      });

      if (error) throw error;

      await fetchTransactions(profile, tenant.id);
      setGeneratedToken(token);
      setIsProcessing(false);
      setTokenStep(4);
    } catch (err: any) {
      alert('Error buying tokens: ' + err.message);
      setIsProcessing(false);
    }
  };

  const closeTokenModal = () => {
    setShowTokenModal(false);
    setTokenStep(1);
    setTokenType(null);
    setTokenNumber('');
    setTokenAmount('');
    setGeneratedToken(null);
    setPaymentMethod(null);
  };

  const openReceipt = (tx: any) => {
    setSelectedTx(tx);
    setShowReceiptModal(true);
  };

  const downloadReceipt = (tx: any) => {
    // Mock download by opening print dialog
    setSelectedTx(tx);
    setShowReceiptModal(true);
    setTimeout(() => {
      window.print();
    }, 500);
  };

  const handleRequestAdvance = async () => {
    console.log('Submitting advance request:', { landlord, advanceAmount, advanceReason });
    
    if (!advanceAmount || !advanceReason) {
      console.warn('Submission blocked: Missing amount or reason');
      return;
    }
    
    if (!landlord) {
      alert("Error: Landlord profile not found. Please ensure your account is correctly set up with a User ID link.");
      console.error('Submission blocked: Landlord state is null');
      return;
    }
    
    setRequestStatus('loading');
    
    try {
      const { error } = await supabase.from('advance_requests').insert({
        landlord_id: landlord.id,
        amount_requested: parseFloat(advanceAmount),
        reason: advanceReason,
        status: 'Pending'
      });

      if (error) throw error;
      
      // Refresh the list
      const { data: advances } = await supabase
        .from('advance_requests')
        .select('*')
        .order('created_at', { ascending: false });
      setAdvanceRequests(advances || []);
      
      setRequestStatus('success');
      setTimeout(() => {
        setShowAdvanceModal(false);
        setRequestStatus('idle');
        setAdvanceAmount('');
        setAdvanceReason('');
      }, 2000);
    } catch (err: any) {
      alert('Error requesting advance: ' + err.message);
      setRequestStatus('idle');
    }
  };

  const isTenant = role === 'tenant';
  const isLandlord = role === 'landlord';



  const filteredTransactions = txHistory; // Already filtered by server

  if (loading) {
    return (
      <DashboardLayout>
        <div className="flex items-center justify-center min-h-[60vh]">
          <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
        </div>
      </DashboardLayout>
    );
  }
  return (
    <>
      <DashboardLayout>
      <div className="space-y-8 animate-in fade-in duration-500">
        {/* Header */}
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">
              {isTenant ? 'Financial Statement' : 'Financial Management'}
            </h1>
            <p className="text-slate-500 mt-1 font-medium italic">
              {isTenant ? 'Manage your payments, bills, and utility tokens.' : 'Comprehensive financial overview and transaction tracking.'}
            </p>
          </div>
          {isTenant && (
            <div className="flex gap-3">
              <button 
                onClick={() => setShowPaymentModal(true)}
                className="px-6 py-3 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all flex items-center gap-2"
              >
                <Plus size={16} /> Pay Bill
              </button>
              <button 
                onClick={() => setShowTokenModal(true)}
                className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all flex items-center gap-2"
              >
                <Zap size={16} /> Buy Tokens
              </button>
            </div>
          )}
          {isLandlord && (
            <div className="flex gap-3">
              <button 
                onClick={() => setShowAdvanceModal(true)}
                className="px-6 py-3 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all flex items-center gap-2"
              >
                <Plus size={16} /> Request Advance
              </button>
              <button 
                className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all flex items-center gap-2"
              >
                <FileDown size={16} /> Export Report
              </button>
            </div>
          )}
        </div>

        {/* Info Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-green-500 relative overflow-hidden group">
            <h3 className="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-2">
              {isTenant ? 'Total Paid (YTD)' : (isLandlord ? 'Monthly Revenue' : 'Total Revenue')}
            </h3>
            <div className="flex items-end justify-between relative z-10">
              <div>
                <p className="text-3xl font-black text-slate-900 dark:text-white">KSh {totalPaid.toLocaleString()}</p>
                <p className="text-xs text-green-500 flex items-center mt-1 font-bold">
                  <ArrowUpRight size={14} /> {isTenant ? 'On track' : '+12% from last month'}
                </p>
              </div>
              <div className="p-3 bg-green-50 dark:bg-green-900/20 text-green-500 rounded-xl group-hover:scale-110 transition-transform">
                <DollarSign size={24} />
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-accent-gold relative overflow-hidden group">
            <h3 className="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-2">
              {isTenant ? 'Current Balance' : (isLandlord ? 'Monthly Outstanding' : 'Outstanding Balances')}
            </h3>
            <div className="flex items-end justify-between relative z-10">
              <div>
                <p className="text-3xl font-black text-slate-900 dark:text-white">KSh {balance.toLocaleString()}</p>
                <p className="text-xs text-green-500 flex items-center mt-1 font-bold">
                   {isTenant ? (balance === 0 ? 'Account Clear' : `KSh ${balance.toLocaleString()} Due`) : <><ArrowDownRight size={14} /> -4% improvement</>}
                </p>
              </div>
              <div className="p-3 bg-amber-50 dark:bg-amber-900/20 text-accent-gold rounded-xl group-hover:scale-110 transition-transform">
                <Wallet size={24} />
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-blue-500 relative overflow-hidden group">
            <h3 className="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-2">
              {isTenant ? 'Estimated Utilities' : 'Next Payout'}
            </h3>
            <div className="flex items-end justify-between relative z-10">
              <div>
                <p className="text-3xl font-black text-slate-900 dark:text-white">KSh {waterBill.toLocaleString()}</p>
                <p className="text-xs text-slate-400 mt-1 font-bold italic">
                  {isTenant ? 'Pending Water/Service' : 'Est. Monday, Mar 15'}
                </p>
              </div>
              <div className="p-3 bg-blue-50 dark:bg-blue-900/20 text-blue-500 rounded-xl group-hover:scale-110 transition-transform">
                {isTenant ? <Droplets size={24} /> : <Download size={24} />}
              </div>
            </div>
          </div>
        </div>

        {/* Transactions Table */}
        <div className="glass-card p-8">
          <div className="flex justify-between items-center mb-8">
            <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
               <History className="text-accent-gold" size={20} /> {isTenant ? 'My Payment History' : (isLandlord ? 'Payout History' : 'Recent Transactions')}
            </h3>
            <button className="text-[10px] font-black text-accent-gold uppercase tracking-[0.2em] hover:opacity-80 transition-opacity flex items-center gap-2">
              <FileDown size={14} /> Download Statement
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="text-slate-500 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="pb-4">Date</th>
                  <th className="pb-4">{isTenant ? 'Bill Type' : (isLandlord ? 'Reference / Method' : 'Tenant / Property')}</th>
                  <th className="pb-4">Amount</th>
                  <th className="pb-4">Status</th>
                  <th className="pb-4 text-right">Documents</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredTransactions.map((tx) => (
                  <tr key={tx.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group">
                    <td className="py-4 text-xs font-bold text-slate-500 dark:text-slate-400 tracking-tighter">
                      {new Date(tx.date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' })}
                    </td>
                    <td className="py-4">
                      {isTenant ? (
                        <p className="font-black text-slate-900 dark:text-white flex items-center gap-2">
                           {tx.type === 'Water' ? <Droplets size={14} className="text-blue-500" /> : <CreditCard size={14} className="text-slate-400" />}
                           {tx.type} Payment
                        </p>
                      ) : (
                        <>
                          <p className="font-black text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{tx.tenant}</p>
                          <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">{tx.property}</p>
                        </>
                      )}
                    </td>
                    <td className="py-4 font-black text-slate-900 dark:text-white text-sm">KSh {tx.amount.toLocaleString()}</td>
                    <td className="py-4">
                      <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${
                        tx.status === 'Paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/30' : 'bg-orange-100 text-orange-700 dark:bg-orange-900/30'
                      }`}>
                        {tx.status}
                      </span>
                    </td>
                    <td className="py-4 text-right">
                       <div className="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                         <button 
                           onClick={() => openReceipt(tx)}
                           className="p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-lg hover:text-accent-gold transition-all" 
                           title="View Receipt"
                         >
                            <Receipt size={16} />
                         </button>
                         <button 
                           onClick={() => downloadReceipt(tx)}
                           className="p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-lg hover:text-accent-gold transition-all" 
                           title="Download Invoice"
                         >
                            <Download size={16} />
                         </button>
                       </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {isLandlord && advanceRequests.length > 0 && (
          <div className="glass-card p-8 mt-8 border-t-4 border-t-amber-500">
            <div className="flex justify-between items-center mb-8">
              <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                 <Plus className="text-accent-gold" size={20} /> My Advance Requests
              </h3>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="text-slate-500 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                    <th className="pb-4">Date</th>
                    <th className="pb-4">Amount Requested</th>
                    <th className="pb-4">Status</th>
                    <th className="pb-4">Admin Notes / Comments</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {advanceRequests.map((req) => (
                    <tr key={req.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors">
                      <td className="py-4 text-xs font-bold text-slate-500">
                        {new Date(req.created_at).toLocaleDateString()}
                      </td>
                      <td className="py-4">
                        <p className="font-black text-slate-900 dark:text-white">KSh {parseFloat(req.amount_requested).toLocaleString()}</p>
                        {req.approved_amount && (
                          <p className="text-[10px] font-black text-green-500 uppercase">Approved: KSh {parseFloat(req.approved_amount).toLocaleString()}</p>
                        )}
                      </td>
                      <td className="py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${
                          req.status === 'Approved' ? 'bg-green-100 text-green-700' : 
                          req.status === 'Rejected' ? 'bg-red-100 text-red-700' : 
                          'bg-amber-100 text-amber-700'
                        }`}>
                          {req.status}
                        </span>
                      </td>
                      <td className="py-4">
                        <p className="text-xs font-medium text-slate-600 dark:text-slate-400 max-w-xs">
                          {req.admin_notes || <span className="italic opacity-50">No comments yet</span>}
                        </p>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {isTenant && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div className="glass-card p-8 border-l-4 border-l-blue-500">
              <h3 className="text-xl font-black text-slate-900 dark:text-white mb-6">Utility Breakdown</h3>
              <div className="space-y-4">
                 <div className="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl">
                    <div className="flex items-center gap-3">
                       <Droplets className="text-blue-500" size={20} />
                       <div>
                          <p className="text-sm font-black text-slate-900 dark:text-white">Water Usage</p>
                          <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">A/C No: 4882193</p>
                       </div>
                    </div>
                    <p className="font-black text-slate-900 dark:text-white">KSh 1,450</p>
                 </div>
                 <div className="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl">
                    <div className="flex items-center gap-3">
                       <Zap className="text-accent-gold" size={20} />
                       <div>
                          <p className="text-sm font-black text-slate-900 dark:text-white">Electricity Status</p>
                          <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Meter: 1422091</p>
                       </div>
                    </div>
                    <p className="font-black text-orange-500 text-xs">Low Units</p>
                 </div>
              </div>
            </div>

              <div className="p-8 bg-linear-to-r from-accent-gold to-amber-500 text-white rounded-3xl shadow-xl flex justify-between items-center">
               <div className="relative z-10">
                 <h3 className="text-xl font-black mb-4">Quick Pay via M-Pesa</h3>
                 <p className="text-slate-400 text-sm mb-6 font-medium">Use M-Pesa Paybill <span className="text-accent-gold font-black">222999</span> for instant account clearance.</p>
                 <button className="w-full py-4 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-[0.2em] shadow-lg hover:translate-y-[-2px] transition-all">
                    Initiate STK Push
                 </button>
               </div>
               <div className="absolute top-[-20%] right-[-10%] w-64 h-64 bg-accent-gold/10 rounded-full blur-3xl group-hover:scale-110 transition-all duration-1000"></div>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>

    {/* Advance Request Modal */}
    {showAdvanceModal && (
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
        <div className="glass-card w-full max-w-lg p-8 relative overflow-hidden border-t-4 border-t-accent-gold shadow-2xl">
          <button 
            onClick={() => setShowAdvanceModal(false)}
            className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white transition-colors"
          >
            <Plus className="rotate-45" size={24} />
          </button>

          {requestStatus === 'success' ? (
            <div className="text-center py-12 space-y-4 animate-in zoom-in duration-300">
              <div className="w-20 h-20 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <Plus size={40} className="rotate-45" />
              </div>
              <h2 className="text-2xl font-black text-slate-900 dark:text-white">Request Sent!</h2>
              <p className="text-slate-500">Your advance request has been submitted for approval.</p>
            </div>
          ) : (
            <div className="space-y-6">
              <div className="text-center">
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Request Advance</h2>
                <p className="text-slate-500 text-sm mt-1">Request funds from your upcoming property revenue.</p>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block">Amount Requested (KSh)</label>
                  <input 
                    type="number" 
                    value={advanceAmount}
                    onChange={(e) => setAdvanceAmount(e.target.value)}
                    placeholder="e.g. 50,000"
                    className="w-full p-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-lg font-black focus:outline-none focus:border-accent-gold transition-all"
                  />
                </div>
                <div>
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block">Reason for Advance</label>
                  <textarea 
                    value={advanceReason}
                    onChange={(e) => setAdvanceReason(e.target.value)}
                    placeholder="Briefly explain why you need an advance..."
                    className="w-full p-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none focus:border-accent-gold transition-all min-h-[100px]"
                  />
                </div>
              </div>

              <button 
                onClick={handleRequestAdvance}
                disabled={!landlord || !advanceAmount || !advanceReason || requestStatus === 'loading'}
                className="w-full py-4 bg-slate-900 dark:bg-accent-gold text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all disabled:opacity-50"
              >
                {!landlord ? 'Account Error' : (requestStatus === 'loading' ? 'Submitting...' : 'Submit Request')}
              </button>
            </div>
          )}
        </div>
      </div>
    )}

    <PaymentModal 
      isOpen={showPaymentModal} 
      onClose={() => setShowPaymentModal(false)} 
      onSuccess={handlePaymentSuccess}
      balance={balance}
    />

    {/* Token Purchase Modal */}
    {showTokenModal && (
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
        <div className="glass-card w-full max-w-lg p-8 relative overflow-hidden border-t-4 border-t-accent-gold shadow-2xl">
          <button 
            onClick={closeTokenModal}
            className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white transition-colors"
          >
            <Plus className="rotate-45" size={24} />
          </button>

          {tokenStep === 1 && (
            <div className="space-y-6 animate-in slide-in-from-bottom-4 duration-500">
              <div className="text-center">
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Utility Type</h2>
                <p className="text-slate-500 text-sm mt-1">Which tokens are you buying today?</p>
              </div>
              
              <div className="grid grid-cols-2 gap-4">
                {[
                  { id: 'Electricity', icon: Zap, color: 'text-accent-gold' },
                  { id: 'Water', icon: Droplets, color: 'text-blue-500' }
                ].map((item) => (
                  <button
                    key={item.id}
                    onClick={() => { setTokenType(item.id as any); setTokenStep(2); }}
                    className="p-8 bg-slate-50/50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-800 rounded-3xl hover:border-accent-gold transition-all group active:scale-95 flex flex-col items-center gap-4"
                  >
                    <div className={`p-4 bg-white dark:bg-slate-800 rounded-2xl shadow-sm ${item.color}`}>
                      <item.icon size={32} />
                    </div>
                    <p className="font-black text-slate-900 dark:text-white tracking-tight">{item.id}</p>
                  </button>
                ))}
              </div>
            </div>
          )}

          {tokenStep === 2 && (
            <div className="space-y-6 animate-in slide-in-from-bottom-4 duration-500">
              <div className="text-center">
                 <button onClick={() => setTokenStep(1)} className="text-[10px] font-black text-accent-gold uppercase tracking-widest mb-2 hover:underline">← Go Back</button>
                 <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">{tokenType} Details</h2>
              </div>

              <div className="space-y-4">
                 <div>
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block">Account / Meter Number</label>
                    <input 
                      type="text" 
                      value={tokenNumber}
                      onChange={(e) => setTokenNumber(e.target.value)}
                      placeholder={tokenType === 'Electricity' ? 'Meter Number (e.g. 1422091)' : 'Account Number (e.g. 4882193)'}
                      className="w-full p-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none focus:border-accent-gold transition-all"
                    />
                 </div>
                 <div>
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block">Amount (KSh)</label>
                    <input 
                      type="number" 
                      value={tokenAmount}
                      onChange={(e) => setTokenAmount(e.target.value)}
                      placeholder="e.g. 1000"
                      className="w-full p-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-lg font-black focus:outline-none focus:border-accent-gold transition-all"
                    />
                 </div>
              </div>

              <button 
                onClick={() => setTokenStep(3)}
                disabled={!tokenNumber || !tokenAmount}
                className="w-full py-4 bg-slate-900 dark:bg-accent-gold text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] transition-all disabled:opacity-50"
              >
                Proceed to Payment
              </button>
            </div>
          )}

          {tokenStep === 3 && (
            <div className="space-y-6 animate-in slide-in-from-bottom-4 duration-500">
              <div className="text-center">
                 <button onClick={() => setTokenStep(2)} className="text-[10px] font-black text-accent-gold uppercase tracking-widest mb-2 hover:underline">← Go Back</button>
                 <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Select Method</h2>
                 <p className="text-slate-500 text-sm mt-1">Paying <span className="font-bold text-slate-900 dark:text-white">KSh {tokenAmount}</span> for {tokenType} Tokens</p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                {[
                  { id: 'M-Pesa', logo: 'M' },
                  { id: 'Airtel Money', logo: 'A' },
                  { id: 'Bank Transfer', logo: 'B' },
                  { id: 'Manual', logo: 'P' }
                ].map((method) => (
                  <button
                    key={method.id}
                    onClick={() => setPaymentMethod(method.id)}
                    className={`p-6 rounded-2xl border-2 transition-all flex flex-col items-center gap-2 group ${
                      paymentMethod === method.id 
                        ? 'border-accent-gold bg-accent-gold/5' 
                        : 'border-slate-100 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-600'
                    }`}
                  >
                    <div className={`w-12 h-12 flex items-center justify-center rounded-xl font-black text-xl shadow-inner ${
                      paymentMethod === method.id ? 'bg-accent-gold text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'
                    }`}>
                      {method.logo}
                    </div>
                    <p className="font-black text-[10px] uppercase tracking-widest text-slate-900 dark:text-white">{method.id}</p>
                  </button>
                ))}
              </div>

              <button 
                onClick={handleBuyTokens}
                disabled={!paymentMethod || isProcessing}
                className="w-full py-4 bg-slate-900 dark:bg-accent-gold text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all disabled:opacity-50"
              >
                {isProcessing ? 'Validating Account...' : 'Confirm Purchase'}
              </button>
            </div>
          )}

          {tokenStep === 4 && (
            <div className="space-y-6 animate-in zoom-in duration-500 flex flex-col items-center text-center">
              <div className="w-20 h-20 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mb-2">
                 <Zap size={40} fill="currentColor" />
              </div>
              <div>
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Token Generated!</h2>
                <p className="text-slate-500 text-sm mt-1">Successfully bought {tokenType} tokens for KSh {tokenAmount}</p>
              </div>

              <div className="w-full p-6 bg-slate-900 rounded-3xl border border-slate-800 shadow-inner relative overflow-hidden">
                 <p className="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em] mb-3">Your {tokenType} Token</p>
                 <p className="text-3xl font-black text-white tracking-widest font-mono select-all">
                    {generatedToken}
                 </p>
                 <div className="absolute top-0 right-0 p-4 opacity-10">
                    <Zap size={80} />
                 </div>
              </div>

              <p className="text-xs text-slate-400 font-medium">This token has also been sent to your registered <span className="text-accent-gold font-bold">Email & Phone</span>.</p>

              <button 
                onClick={closeTokenModal}
                className="w-full py-4 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-2xl font-black text-sm uppercase tracking-[0.2em] hover:bg-slate-200 dark:hover:bg-slate-700 transition-all"
              >
                Dismiss
              </button>
            </div>
          )}
        </div>
      </div>
    )}

    {/* Receipt / Invoice Modal */}
    {showReceiptModal && selectedTx && (
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300 print:bg-white print:p-0">
        <div className="bg-white dark:bg-slate-950 w-full max-w-2xl p-0 relative overflow-hidden shadow-2xl rounded-3xl print:shadow-none print:rounded-none">
          {/* Controls - Hidden on print */}
          <div className="absolute top-6 right-6 flex gap-3 print:hidden">
            <button 
              onClick={() => window.print()}
              className="p-3 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-xl hover:bg-accent-gold hover:text-slate-900 transition-all shadow-sm"
              title="Print / Download"
            >
              <FileDown size={20} />
            </button>
            <button 
              onClick={() => setShowReceiptModal(false)}
              className="p-3 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-xl hover:bg-red-500 hover:text-white transition-all shadow-sm"
            >
              <Plus className="rotate-45" size={20} />
            </button>
          </div>

          {/* Receipt Content */}
          <div className="p-12 space-y-10">
             {/* Header */}
             <div className="flex justify-between items-start border-b border-slate-100 dark:border-slate-800 pb-10">
                <div className="space-y-4">
                   <div className="flex items-center gap-3">
                      <div className="w-12 h-12 bg-slate-900 flex items-center justify-center rounded-xl shadow-lg ring-4 ring-slate-100 dark:ring-slate-800">
                         <span className="text-accent-gold font-black text-xl italic">P</span>
                      </div>
                      <h2 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tighter">Primelink <span className="text-accent-gold">Management</span></h2>
                   </div>
                   <div className="text-[10px] uppercase font-black tracking-widest text-slate-400 leading-relaxed">
                      <p>P.O. Box 1022 - 00100</p>
                      <p>Nairobi, Kenya</p>
                      <p>support@primelink.co.ke</p>
                   </div>
                </div>
                <div className="text-right">
                   <h3 className="text-4xl font-black text-slate-200 dark:text-slate-800 uppercase tracking-[0.2em] select-none">RECEIPT</h3>
                   <p className="text-xs font-black text-slate-900 dark:text-white mt-1">NO: #{selectedTx.id.toUpperCase()}</p>
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-1">Date: {new Date(selectedTx.date).toLocaleDateString()}</p>
                </div>
             </div>

             {/* Bill To / Details */}
             <div className="grid grid-cols-2 gap-12">
                <div className="space-y-3">
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Received From:</p>
                   <div>
                      <p className="font-black text-slate-900 dark:text-white text-lg leading-none">{selectedTx.tenant}</p>
                      <p className="text-[10px] font-black text-slate-500 uppercase tracking-widest mt-1">{selectedTx.property}</p>
                   </div>
                </div>
                <div className="space-y-3 text-right">
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Status:</p>
                   <div>
                      <span className="px-4 py-2 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full text-[10px] font-black uppercase tracking-widest">
                         {selectedTx.status}
                      </span>
                   </div>
                </div>
             </div>

             {/* Itemization */}
             <div className="border border-slate-100 dark:border-slate-800 rounded-3xl overflow-hidden shadow-sm">
                <table className="w-full text-left">
                   <thead className="bg-slate-50 dark:bg-slate-900">
                      <tr className="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800">
                         <th className="px-6 py-4">Item Description</th>
                         <th className="px-6 py-4 text-right">Total Amount</th>
                      </tr>
                   </thead>
                   <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      <tr>
                         <td className="px-6 py-8">
                            <p className="font-black text-slate-900 dark:text-white">{selectedTx.type} Payment</p>
                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-1">Period: March 2024</p>
                         </td>
                         <td className="px-6 py-8 text-right font-black text-slate-900 dark:text-white text-lg">
                            KSh {selectedTx.amount.toLocaleString()}
                         </td>
                      </tr>
                   </tbody>
                   <tfoot className="bg-slate-50 dark:bg-slate-900">
                      <tr className="text-slate-900 dark:text-white">
                         <td className="px-6 py-6 font-black uppercase tracking-widest text-[10px]">Grand Total</td>
                         <td className="px-6 py-6 text-right font-black text-2xl tracking-tighter">KSh {selectedTx.amount.toLocaleString()}</td>
                      </tr>
                   </tfoot>
                </table>
             </div>

             {/* Footer Info */}
             <div className="flex justify-between items-center py-6 border-t border-dashed border-slate-200 dark:border-slate-800">
                <div className="space-y-1">
                   <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Method</p>
                   <p className="text-xs font-black text-slate-900 dark:text-white flex items-center gap-2 uppercase italic">
                      <Wallet size={12} className="text-accent-gold" /> {selectedTx.method}
                   </p>
                </div>
                <div className="text-right">
                   <div className="w-24 h-24 bg-slate-100 dark:bg-slate-900 rounded-xl flex flex-col items-center justify-center border border-slate-200 dark:border-slate-800 opacity-50 ml-auto">
                      <div className="w-16 h-1 bg-slate-300 dark:bg-slate-700 rounded-full mb-1"></div>
                      <div className="w-12 h-1 bg-slate-300 dark:bg-slate-700 rounded-full mb-1"></div>
                      <p className="text-[8px] font-black text-slate-400 uppercase mt-2">Authorized Seal</p>
                   </div>
                </div>
             </div>

             <div className="text-center">
                <p className="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em]">Thank you for choosing Primelink Management System</p>
             </div>
          </div>
          
          {/* Decorative accents */}
          <div className="absolute bottom-0 left-0 w-full h-2 bg-linear-to-r from-accent-gold via-slate-900 to-accent-gold"></div>
        </div>
      </div>
    )}
    </>
  );
}
