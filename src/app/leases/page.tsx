'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockTenants } from '@/lib/mock-data';
import { 
  FileText, 
  Search, 
  Plus, 
  Filter, 
  Calendar, 
  MoreVertical,
  CheckCircle,
  AlertTriangle,
  Clock,
  Building2,
  X,
  Download,
  RotateCcw,
  Eye
} from 'lucide-react';
import { useRouter } from 'next/navigation';
import { supabase } from '@/lib/supabase';
import { useEffect } from 'react';

export default function LeasesPage() {
  const router = useRouter();
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState<string>('All');
  const [showAddModal, setShowAddModal] = useState(false);
  const [role, setRole] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function checkRole() {
      const { data: { user } } = await supabase.auth.getUser();
      if (user) {
        setRole(user.user_metadata?.role || 'tenant');
      }
      setLoading(false);
    }
    checkRole();
  }, []);

  // Derive leases data directly from tenants in our mock system
  const leases = mockTenants.map(tenant => ({
    id: `lease-${tenant.id}`,
    tenantId: tenant.id,
    tenantName: tenant.name,
    tenantImage: tenant.profileImage || `https://ui-avatars.com/api/?name=${tenant.name}&background=random`,
    propertyId: tenant.propertyId,
    propertyName: tenant.propertyName,
    unit: tenant.unit,
    startDate: tenant.leaseStart,
    endDate: tenant.leaseEnd,
    signedDate: 'Jan 01, 2024', // Mock signed date
    amount: tenant.rentAmount,
    status: tenant.status === 'Active' ? 'Active' : tenant.status === 'Pending' ? 'Pending' : 'Terminated'
  }));

  const isTenant = role === 'tenant';

  // Filtering
  const filteredLeases = leases.filter(lease => {
    // If tenant, only show their own (Mock: first lease for demonstration)
    if (isTenant) {
      return lease.tenantName === "John Doe"; // Default mock tenant name
    }

    const matchesSearch = lease.tenantName.toLowerCase().includes(searchTerm.toLowerCase()) || 
                          lease.propertyName.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'All' || lease.status === filterStatus;
    
    return matchesSearch && matchesStatus;
  });

  // Calculate Metrics
  const activeLeases = leases.filter(l => l.status === 'Active').length;
  const pendingLeases = leases.filter(l => l.status === 'Pending').length;
  const totalMonthlyValue = leases.reduce((sum, l) => l.status === 'Active' ? sum + l.amount : sum, 0);

  const statusColors: Record<string, string> = {
    Active: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Pending: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Terminated: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

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
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">
              {isTenant ? 'My Lease Agreements' : 'Lease Management'}
            </h1>
            <p className="text-slate-500 mt-1 font-medium">
              {isTenant ? 'View and manage your residential agreements.' : 'Track, create, and manage tenant agreements.'}
            </p>
          </div>
          {!isTenant && (
            <button 
              onClick={() => setShowAddModal(true)}
              className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
            >
              <Plus size={18} /> New Lease
            </button>
          )}
        </div>

        {/* Metrics Grid (Staff Only) */}
        {!isTenant && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div className="glass-card p-6 border-l-4 border-l-green-500 hover:shadow-xl transition-all hover:scale-[1.02]">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 bg-green-500/10 rounded-2xl flex items-center justify-center text-green-500">
                  <CheckCircle size={24} className="opacity-80" />
                </div>
                <div>
                  <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Active Leases</p>
                  <div className="flex items-end gap-2">
                    <h3 className="text-3xl font-black text-slate-900 dark:text-white">{activeLeases}</h3>
                  </div>
                </div>
              </div>
            </div>

            <div className="glass-card p-6 border-l-4 border-l-accent-gold hover:shadow-xl transition-all hover:scale-[1.02]">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 bg-accent-gold/10 rounded-2xl flex items-center justify-center text-accent-gold">
                  <Clock size={24} className="opacity-80" />
                </div>
                <div>
                  <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Pending Signatures</p>
                  <div className="flex items-end gap-2">
                    <h3 className="text-3xl font-black text-slate-900 dark:text-white">{pendingLeases}</h3>
                  </div>
                </div>
              </div>
            </div>

            <div className="glass-card p-6 border-l-4 border-l-blue-500 hover:shadow-xl transition-all hover:scale-[1.02]">
              <div className="flex items-center gap-4">
                <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                  <FileText size={24} className="opacity-80" />
                </div>
                <div>
                  <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Active Lease Value</p>
                  <div className="flex items-end gap-2">
                    <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {(totalMonthlyValue / 1000).toFixed(1)}k</h3>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Filters Box (Staff Only) */}
        {!isTenant && (
          <div className="glass-card p-4 flex flex-col md:flex-row gap-4 items-center justify-between">
            <div className="relative w-full md:w-96 group">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
              <input 
                type="text" 
                placeholder="Search by tenant or property..." 
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
              />
            </div>
            <div className="flex gap-4 w-full md:w-auto overflow-x-auto custom-scrollbar pb-2 md:pb-0">
              {['All', 'Active', 'Pending', 'Terminated'].map(status => (
                <button
                  key={status}
                  onClick={() => setFilterStatus(status)}
                  className={`px-6 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-all ${
                    filterStatus === status 
                      ? 'bg-slate-900 text-white shadow-lg dark:bg-slate-50 dark:text-slate-900' 
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700'
                  }`}
                >
                  {status}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Leases Data Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[800px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Tenant Info</th>
                  <th className="px-6 py-4">Property Assigned</th>
                  <th className="px-6 py-4">Lease Period</th>
                  <th className="px-6 py-4">Rent/Mo</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {filteredLeases.length > 0 ? (
                    filteredLeases.map((lease) => (
                      <tr 
                        key={lease.id} 
                        className={`hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group ${!isTenant ? 'cursor-pointer' : ''}`}
                        onClick={() => !isTenant && router.push(`/tenants/${lease.tenantId}`)}
                      >
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-3">
                            <img 
                              src={lease.tenantImage} 
                              alt={lease.tenantName} 
                              className="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-800 shadow-sm"
                            />
                            <div>
                              <p className="font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{lease.tenantName}</p>
                              <p className="text-xs text-slate-500 font-mono tracking-wider">{lease.id}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2 text-slate-700 dark:text-slate-300">
                            <Building2 size={16} className="text-slate-400" />
                            <div>
                              <p className="font-bold text-sm">{lease.propertyName}</p>
                              {lease.unit && <p className="text-xs text-slate-500 uppercase tracking-widest font-bold">Unit {lease.unit}</p>}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-sm text-slate-600 dark:text-slate-400 font-semibold">
                          <div className="space-y-1">
                            <div className="flex items-center gap-2">
                              <Calendar size={12} className="text-slate-400" />
                              <span className="text-[10px] uppercase font-black tracking-tighter">Starts:</span> {new Date(lease.startDate).toLocaleDateString()}
                            </div>
                            <div className="flex items-center gap-2">
                              <Calendar size={12} className="text-slate-400" />
                              <span className="text-[10px] uppercase font-black tracking-tighter text-red-500">Expires:</span> {new Date(lease.endDate).toLocaleDateString()}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 font-black text-slate-900 dark:text-white">
                          KSh {lease.amount.toLocaleString()}
                        </td>
                        <td className="px-6 py-4">
                          <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${statusColors[lease.status]}`}>
                            {lease.status}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-right">
                          {isTenant ? (
                            <div className="flex items-center justify-end gap-2">
                              <button className="p-2.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-xl hover:bg-slate-200 dark:hover:bg-slate-700 transition-all group" title="Download PDF">
                                <Download size={18} className="group-hover:text-accent-gold" />
                              </button>
                              <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-[10px] uppercase tracking-widest hover:-translate-y-px transition-all flex items-center gap-2 shadow-md">
                                <RotateCcw size={14} /> Renew
                              </button>
                            </div>
                          ) : (
                            <button className="p-2 hover:bg-white dark:hover:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm opacity-0 group-hover:opacity-100">
                              <MoreVertical size={18} />
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  ) : (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500 bg-slate-50/50 dark:bg-slate-900/10">
                      <FileText size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                      <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No leases found</p>
                      <p className="text-slate-500">We couldn't find any leases matching your current criteria.</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Add Lease Modal (Mock) */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
          <div className="glass-card max-w-2xl w-full p-8 relative flex flex-col items-center text-center">
            <button 
              onClick={() => setShowAddModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="w-20 h-20 bg-accent-gold/10 text-accent-gold rounded-full flex items-center justify-center mb-6">
              <FileText size={40} />
            </div>
            <h2 className="text-2xl font-black text-slate-900 dark:text-white mb-3">Create New Lease</h2>
            <p className="text-slate-500 mb-8 max-w-md">
              The Lease Creation workflow is currently connected directly to the Tenant Onboarding process. To create a new lease, please navigate to the Tenants page and add a new Tenant directly.
            </p>
            <div className="flex gap-4">
              <button 
                onClick={() => setShowAddModal(false)}
                className="px-6 py-3 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
              >
                Cancel
              </button>
              <button 
                onClick={() => router.push('/tenants')}
                className="px-8 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-sm uppercase tracking-widest shadow-xl hover:translate-y-[-2px] transition-all"
              >
                Go To Tenants
              </button>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
