'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { supabase } from '@/lib/supabase';
import { 
  Mail, 
  Phone, 
  Calendar, 
  UserPlus, 
  Search, 
  MoreVertical, 
  X, 
  Check, 
  User, 
  MapPin, 
  DollarSign, 
  Wallet, 
  ArrowRight,
  ChevronRight,
  AlertTriangle
} from 'lucide-react';

import { useRouter } from 'next/navigation';

export default function TenantsPage() {
  const [tenants, setTenants] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [role, setRole] = useState<string | null>(null);
  const [landlordId, setLandlordId] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingBalanceId, setEditingBalanceId] = useState<string | null>(null);
  const [paymentInput, setPaymentInput] = useState<string>('');
  const router = useRouter();

  // Add State
  const [availableProperties, setAvailableProperties] = useState<any[]>([]);
  const [availableUnits, setAvailableUnits] = useState<any[]>([]);
  const [newTenantData, setNewTenantData] = useState({
    fullName: '',
    email: '',
    phone: '',
    idNumber: '',
    propertyId: '',
    unitId: '',
    startDate: '',
    endDate: '',
    rent: '',
  });

  useEffect(() => {
    async function init() {
      const { data: { session } } = await supabase.auth.getSession();
      if (session?.user) {
        const uRole = session.user.user_metadata?.role || 'tenant';
        setRole(uRole);
        
        if (uRole === 'landlord') {
           const { data: lData } = await supabase.from('landlords').select('id').eq('user_id', session.user.id).maybeSingle();
           setLandlordId(lData?.id || null);
        }
      }
      fetchTenants();
      fetchAvailableProperties();
    }
    init();
  }, []);

  const fetchTenants = async () => {
    setLoading(true);
    const { data: { session } } = await supabase.auth.getSession();
    
    // We need tenants joined with their active lease and the related unit/property
    let query = supabase.from('tenants').select(`
      *,
      leases (
        id, status, start_date, end_date, monthly_rent,
        units (
          unit_number,
          properties ( title, landlord_id )
        )
      )
    `);

    const { data, error } = await query;
    if (!error) {
       // Filter and map
       let mapped = data.map(t => {
          const activeLease = t.leases?.find((l: any) => l.status === 'Active');
          return {
             ...t,
             name: t.full_name,
             propertyName: activeLease?.units?.properties?.title || 'N/A',
             landlordId: activeLease?.units?.properties?.landlord_id,
             unit: activeLease?.units?.unit_number,
             leaseStart: activeLease?.start_date,
             leaseEnd: activeLease?.end_date,
             rentAmount: activeLease?.monthly_rent || 0,
             pendingBalance: 0 // Will calculate if needed
          };
       });

        setTenants(mapped);
    }
    setLoading(false);
  };

  const fetchAvailableProperties = async () => {
     let query = supabase.from('properties').select('id, title');
     
     // Note: RLS should handle this, but explicit filter is good for UI clarity
     const { data: { session } } = await supabase.auth.getSession();
     if (session?.user.user_metadata?.role === 'landlord') {
        const { data: lData } = await supabase.from('landlords').select('id').eq('user_id', session.user.id).maybeSingle();
        if (lData) {
           query = query.eq('landlord_id', lData.id);
        }
     }

     const { data } = await query;
     setAvailableProperties(data || []);
  };

  const fetchUnitsForProperty = async (propId: string) => {
     const { data } = await supabase.from('units').select('id, unit_number, rent_amount').eq('property_id', propId).eq('status', 'Available');
     setAvailableUnits(data || []);
  };

  const handleOnboardTenant = async () => {
    // 1. Create Tenant
    const { data: tenant, error: tError } = await supabase
      .from('tenants')
      .insert({
        full_name: newTenantData.fullName,
        email: newTenantData.email,
        phone: newTenantData.phone,
        status: 'Active'
      })
      .select()
      .single();

    if (!tError && tenant) {
      // 2. Create Lease
      const { error: lError } = await supabase
        .from('leases')
        .insert({
          tenant_id: tenant.id,
          unit_id: newTenantData.unitId,
          start_date: newTenantData.startDate,
          end_date: newTenantData.endDate,
          monthly_rent: parseFloat(newTenantData.rent),
          status: 'Active'
        });
      
      if (!lError) {
        // 3. Update Unit Status
        await supabase.from('units').update({ status: 'Occupied' }).eq('id', newTenantData.unitId);
        
        fetchTenants();
        setShowAddModal(false);
        setNewTenantData({
           fullName: '', email: '', phone: '', idNumber: '', propertyId: '', unitId: '', startDate: '', endDate: '', rent: ''
        });
      } else {
        alert(lError.message);
      }
    } else {
      alert(tError?.message || "Error creating tenant");
    }
  };

  const handleRecordPayment = async (tenantId: string) => {
    const amount = parseFloat(paymentInput);
    if (isNaN(amount) || amount <= 0) {
      setEditingBalanceId(null);
      setPaymentInput('');
      return;
    }
    
    // In a real system, this would create a transaction
    const { error } = await supabase.from('transactions').insert({
       tenant_id: tenantId,
       amount: amount,
       transaction_type: 'Rent',
       status: 'Paid',
       payment_method: 'Cash/Other'
    });

    if (!error) {
       fetchTenants();
       setEditingBalanceId(null);
       setPaymentInput('');
    }
  };

  const filteredTenants = tenants.filter(t => 
    t.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    t.propertyName.toLowerCase().includes(searchTerm.toLowerCase()) ||
    t.email.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div className="relative w-full sm:w-96 group">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
            <input 
              type="text" 
              placeholder="Search by name, property or email..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-12 pr-4 py-3 rounded-2xl border-2 border-transparent bg-white dark:bg-slate-900 focus:outline-none focus:ring-4 focus:ring-accent-gold/10 focus:border-accent-gold transition-all shadow-sm"
            />
          </div>
          {(role === 'admin' || role === 'staff') && (
            <button 
              onClick={() => setShowAddModal(true)}
              className="flex items-center justify-center gap-2 px-8 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 transition-all"
            >
              <UserPlus size={18} />
              Onboard Tenant
            </button>
          )}
        </div>

        {/* Tenants Table */}
        <div className="glass-card overflow-hidden border-none shadow-2xl">
          <div className="overflow-x-auto overflow-y-visible">
            <table className="w-full text-left border-collapse min-w-[1000px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-[0.2em] border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-5">Tenant Info</th>
                  <th className="px-6 py-5">Property / Unit</th>
                  <th className="px-6 py-5">Lease Details</th>
                  <th className="px-6 py-5">Monthly Rent</th>
                  {role !== 'landlord' && <th className="px-6 py-5 text-accent-gold">Pending Balance</th>}
                  <th className="px-6 py-5">Status</th>
                  <th className="px-6 py-5"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {loading ? (
                  <tr>
                    <td colSpan={role === 'landlord' ? 6 : 7} className="px-6 py-20 text-center">
                       <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin mx-auto"></div>
                    </td>
                  </tr>
                ) : filteredTenants.map((tenant) => (
                  <tr 
                    key={tenant.id} 
                    className={`transition-all group ${role === 'landlord' ? 'cursor-default' : 'hover:bg-slate-50/30 dark:hover:bg-slate-900/30 cursor-pointer'}`}
                  >
                    <td className="px-6 py-4" onClick={() => role !== 'landlord' && router.push(`/tenants/${tenant.id}`)}>
                      <div className="flex items-center gap-4">
                        <div className="relative">
                          <img 
                            src={tenant.profileImage} 
                            alt={tenant.name} 
                            className="w-12 h-12 rounded-2xl object-cover ring-2 ring-white dark:ring-slate-800 shadow-lg"
                          />
                          <div className={`absolute -bottom-1 -right-1 w-4 h-4 rounded-full border-2 border-white dark:border-slate-900 ${tenant.status === 'Active' ? 'bg-green-500' : 'bg-slate-400'}`}></div>
                        </div>
                        <div>
                          <p className="font-black text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{tenant.name}</p>
                          <p className="text-[10px] text-slate-500 font-bold uppercase tracking-tight">{tenant.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className={`px-6 py-4 ${role === 'landlord' ? 'cursor-default' : 'cursor-pointer'}`} onClick={() => role !== 'landlord' && router.push(`/tenants/${tenant.id}`)}>
                      <p className="font-bold text-slate-800 dark:text-slate-200">{tenant.propertyName}</p>
                      <p className="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-0.5">Unit {tenant.unit || 'N/A'}</p>
                    </td>
                    <td className={`px-6 py-4 text-sm text-slate-500 dark:text-slate-400 ${role === 'landlord' ? 'cursor-default' : 'cursor-pointer'}`} onClick={() => role !== 'landlord' && router.push(`/tenants/${tenant.id}`)}>
                      <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2 font-bold text-slate-600 dark:text-slate-300">
                          <Calendar size={14} className="text-accent-gold" />
                          <span>{new Date(tenant.leaseStart).toLocaleDateString()}</span>
                        </div>
                        <p className="text-[9px] font-black uppercase tracking-widest text-slate-400 pl-6">Ends {new Date(tenant.leaseEnd).toLocaleDateString()}</p>
                      </div>
                    </td>
                    <td className={`px-6 py-4 font-black text-slate-900 dark:text-white ${role === 'landlord' ? 'cursor-default' : 'cursor-pointer'}`} onClick={() => role !== 'landlord' && router.push(`/tenants/${tenant.id}`)}>
                      <span className="text-slate-400 mr-1 text-xs font-bold">KSh</span>
                      {tenant.rentAmount.toLocaleString()}
                    </td>
                    {role !== 'landlord' && (
                      <td className="px-6 py-4 relative">
                        {editingBalanceId === tenant.id ? (
                          <div className="flex items-center gap-2 animate-in slide-in-from-right-2 duration-200">
                            <div className="relative">
                               <input 
                                autoFocus
                                type="number" 
                                value={paymentInput}
                                onChange={(e) => setPaymentInput(e.target.value)}
                                onKeyDown={(e) => {
                                  if (e.key === 'Enter') handleRecordPayment(tenant.id);
                                  if (e.key === 'Escape') setEditingBalanceId(null);
                                }}
                                className="w-32 bg-white dark:bg-slate-950 border-2 border-accent-gold rounded-xl px-3 py-2 text-sm font-black shadow-lg outline-none"
                                placeholder="Paid amt..."
                              />
                              <Wallet size={12} className="absolute right-3 top-1/2 -translate-y-1/2 text-accent-gold opacity-50" />
                            </div>
                            <button 
                              onClick={() => handleRecordPayment(tenant.id)}
                              className="p-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-lg hover:scale-110 transition-transform shadow-md"
                            >
                              <Check size={16} />
                            </button>
                            <button 
                              onClick={() => setEditingBalanceId(null)}
                              className="p-2 bg-red-500 text-white rounded-lg hover:scale-110 transition-transform shadow-md"
                            >
                              <X size={16} />
                            </button>
                          </div>
                        ) : (
                          <button 
                            onClick={(e) => {
                              e.stopPropagation();
                              setEditingBalanceId(tenant.id);
                              setPaymentInput('');
                            }}
                            className={`group/balance px-4 py-3 rounded-2xl transition-all border-2 border-transparent hover:border-accent-gold/40 flex flex-col items-start min-w-[140px] ${
                              tenant.pendingBalance > 0 
                                ? 'bg-red-500/5 hover:bg-red-500/10' 
                                : 'bg-emerald-500/5 hover:bg-emerald-500/10'
                            }`}
                          >
                            <div className="flex items-center gap-2">
                               <span className={`text-sm font-black ${tenant.pendingBalance > 0 ? 'text-red-500' : 'text-emerald-500'}`}>
                                  KSh {tenant.pendingBalance.toLocaleString()}
                               </span>
                               {tenant.pendingBalance > 0 && <AlertTriangle size={14} className="text-red-500 animate-pulse" />}
                            </div>
                            <span className="text-[8px] font-black text-slate-400 uppercase tracking-[0.2em] mt-1 flex items-center gap-1.5 overflow-hidden">
                               <span className="whitespace-nowrap group-hover/balance:translate-x-0 -translate-x-full opacity-0 group-hover/balance:opacity-100 transition-all duration-300">RECORD PAYMENT</span>
                               <ChevronRight size={10} className="group-hover/balance:translate-x-2 transition-transform duration-300" />
                            </span>
                          </button>
                        )}
                      </td>
                    )}
                    <td className={`px-6 py-4 ${role === 'landlord' ? 'cursor-default' : 'cursor-pointer'}`} onClick={() => role !== 'landlord' && router.push(`/tenants/${tenant.id}`)}>
                      <span className={`px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${
                        tenant.status === 'Active' ? 'bg-green-500/10 text-green-600 dark:text-green-400' : 
                        tenant.status === 'Pending' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400' : 
                        'bg-red-500/10 text-red-600 dark:text-red-400'
                      }`}>
                        {tenant.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-right">
                      {role !== 'landlord' && (
                        <button className="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm border border-transparent hover:border-slate-200 dark:hover:border-slate-700">
                          <MoreVertical size={20} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          
          {filteredTenants.length === 0 && (
            <div className="py-20 text-center bg-white dark:bg-slate-900 border-t border-slate-50 dark:border-slate-800">
               <div className="w-16 h-16 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                  <User size={32} />
               </div>
               <h3 className="text-lg font-black text-slate-900 dark:text-white uppercase tracking-tight">No Tenants Found</h3>
               <p className="text-slate-500 text-sm italic">Adjust your search or add a new tenant to the system.</p>
            </div>
          )}
        </div>

        {/* ADD TENANT MODAL */}
        {showAddModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-2xl bg-white dark:bg-slate-900 shadow-2xl overflow-hidden flex flex-col">
              <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-950/50">
                <h3 className="text-xl font-bold text-slate-900 dark:text-white">Register New Tenant</h3>
                <button 
                  onClick={() => setShowAddModal(false)}
                  className="p-2 transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white bg-white dark:bg-slate-800 rounded-xl shadow-sm"
                >
                  <X size={20} />
                </button>
              </div>

              <div className="p-8 overflow-y-auto max-h-[70vh] custom-scrollbar">
                <div className="space-y-6">
                  {/* Personal Information */}
                  <div className="space-y-4">
                    <h4 className="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2">1. Personal Information</h4>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Full Name</label>
                        <input type="text" placeholder="John Doe" className="form-input" value={newTenantData.fullName} onChange={(e) => setNewTenantData({...newTenantData, fullName: e.target.value})} />
                      </div>
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Email Address</label>
                        <input type="email" placeholder="john@example.com" className="form-input" value={newTenantData.email} onChange={(e) => setNewTenantData({...newTenantData, email: e.target.value})} />
                      </div>
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Phone Number</label>
                        <input type="tel" placeholder="+254 700 000000" className="form-input" value={newTenantData.phone} onChange={(e) => setNewTenantData({...newTenantData, phone: e.target.value})} />
                      </div>
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">National ID Number</label>
                        <input type="text" placeholder="12345678" className="form-input font-mono" value={newTenantData.idNumber} onChange={(e) => setNewTenantData({...newTenantData, idNumber: e.target.value})} />
                      </div>
                    </div>
                  </div>

                  {/* Property Assignment */}
                  <div className="space-y-4">
                    <h4 className="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2">2. Property Assignment</h4>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Select Property</label>
                        <select className="form-select font-bold" value={newTenantData.propertyId} onChange={(e) => { 
                          setNewTenantData({...newTenantData, propertyId: e.target.value});
                          fetchUnitsForProperty(e.target.value);
                        }}>
                          <option value="">-- Choose Property --</option>
                          {availableProperties.map(p => (
                             <option key={p.id} value={p.id}>{p.title}</option>
                          ))}
                        </select>
                      </div>
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Unit / House Number</label>
                        <select className="form-select font-bold" value={newTenantData.unitId} onChange={(e) => {
                          const unit = availableUnits.find(u => u.id === e.target.value);
                          setNewTenantData({...newTenantData, unitId: e.target.value, rent: unit?.rent_amount || ''});
                        }}>
                          <option value="">-- Select Unit --</option>
                          {availableUnits.map(u => (
                             <option key={u.id} value={u.id}>{u.unit_number} (KSh {u.rent_amount})</option>
                          ))}
                        </select>
                      </div>
                    </div>
                  </div>

                  {/* Lease Details */}
                  <div className="space-y-4">
                    <h4 className="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2">3. Lease Details</h4>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Lease Start Date</label>
                        <input type="date" className="form-input" value={newTenantData.startDate} onChange={(e) => setNewTenantData({...newTenantData, startDate: e.target.value})} />
                      </div>
                      <div className="form-group">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Lease End Date</label>
                        <input type="date" className="form-input" value={newTenantData.endDate} onChange={(e) => setNewTenantData({...newTenantData, endDate: e.target.value})} />
                      </div>
                      <div className="form-group sm:col-span-2">
                        <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Monthly Rent Amount</label>
                        <div className="relative">
                          <span className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm">KSh</span>
                          <input type="number" placeholder="45000" className="form-input pl-14 font-black text-lg" value={newTenantData.rent} onChange={(e) => setNewTenantData({...newTenantData, rent: e.target.value})} />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div className="p-8 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 flex justify-end gap-4">
                <button 
                  onClick={() => setShowAddModal(false)}
                  className="px-8 py-3 text-sm font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 transition-colors"
                >
                  Discard
                </button>
                <button 
                  onClick={handleOnboardTenant}
                  className="px-10 py-3.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2"
                >
                  <Check size={20} /> Onboard Tenant
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
