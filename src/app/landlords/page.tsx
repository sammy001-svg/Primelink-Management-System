'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockLandlords, mockProperties } from '@/lib/mock-data';
import { 
  Briefcase, 
  Search, 
  Plus, 
  Building2, 
  DollarSign, 
  ArrowRight,
  TrendingUp,
  Wallet,
  MoreVertical,
  ExternalLink,
  ChevronRight,
  User
} from 'lucide-react';
import { useRouter } from 'next/navigation';
import { RoleGuard } from '@/components/RoleGuard';

export default function LandlordsPage() {
  return (
    <RoleGuard allowedRoles={['admin', 'staff']}>
      <LandlordsContent />
    </RoleGuard>
  );
}

function LandlordsContent() {
  const router = useRouter();
  const [searchTerm, setSearchTerm] = useState('');

  // Metrics
  const totalLandlords = mockLandlords.length;
  const totalPropertiesOwned = mockLandlords.reduce((sum, l) => sum + l.propertyIds.length, 0);
  const totalPendingPayouts = mockLandlords.reduce((sum, l) => sum + l.pendingPayout, 0);
  const totalAdvances = mockLandlords.reduce((sum, l) => sum + l.advancePaid, 0);

  const filteredLandlords = mockLandlords.filter(l => 
    l.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    l.email.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Landlord Management</h1>
            <p className="text-slate-500 mt-1 font-medium">Manage property owners, portfolios, and payouts.</p>
          </div>
          <button 
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2"
          >
            <Plus size={18} /> Add Landlord
          </button>
        </div>

        {/* Info Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <div className="glass-card p-6 border-b-4 border-b-slate-900 dark:border-b-white">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Landlords</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{totalLandlords}</h3>
              </div>
              <div className="p-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-500">
                <Briefcase size={20} />
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-b-4 border-b-blue-500">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Portfolio Size</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{totalPropertiesOwned}</h3>
                <p className="text-xs text-slate-500 mt-1 font-bold">Managed Properties</p>
              </div>
              <div className="p-3 bg-blue-50 text-blue-500 rounded-xl">
                <Building2 size={20} />
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-b-4 border-b-accent-gold">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Pending Payouts</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {(totalPendingPayouts/1000).toFixed(1)}k</h3>
                <p className="text-xs text-slate-500 mt-1 font-bold">Awaiting processing</p>
              </div>
              <div className="p-3 bg-amber-50 text-accent-gold rounded-xl">
                <Wallet size={20} />
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-b-4 border-b-green-500">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Advance Payments</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {(totalAdvances/1000).toFixed(1)}k</h3>
                <p className="text-xs text-slate-500 mt-1 font-bold">Active advances</p>
              </div>
              <div className="p-3 bg-green-50 text-green-500 rounded-xl">
                <TrendingUp size={20} />
              </div>
            </div>
          </div>
        </div>

        {/* Search & List */}
        <div className="space-y-4">
          <div className="glass-card p-4">
            <div className="relative group">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
              <input 
                type="text" 
                placeholder="Search landlords by name, email or property..." 
                className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-3.5 text-sm font-bold transition-all outline-none"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
          </div>

          <div className="glass-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                    <th className="px-6 py-4">Landlord Info</th>
                    <th className="px-6 py-4">Portfolio</th>
                    <th className="px-6 py-4">Total Earned</th>
                    <th className="px-6 py-4">Pending Payout</th>
                    <th className="px-6 py-4">Advance</th>
                    <th className="px-6 py-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {filteredLandlords.map((landlord) => (
                    <tr 
                      key={landlord.id} 
                      className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors cursor-pointer group"
                      onClick={() => router.push(`/landlords/${landlord.id}`)}
                    >
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden">
                            {landlord.profileImage ? (
                              <img src={landlord.profileImage} alt={landlord.name} className="w-full h-full object-cover" />
                            ) : (
                              <User size={20} className="text-slate-400" />
                            )}
                          </div>
                          <div>
                            <p className="font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{landlord.name}</p>
                            <p className="text-xs text-slate-500">{landlord.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <Building2 size={14} className="text-slate-400" />
                          <span className="text-sm font-bold text-slate-700 dark:text-slate-300">{landlord.propertyIds.length} Properties</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 font-black text-slate-900 dark:text-white text-sm">
                        KSh {landlord.totalEarned.toLocaleString()}
                      </td>
                      <td className="px-6 py-4">
                         <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${
                           landlord.pendingPayout > 0 
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30' 
                            : 'bg-slate-100 text-slate-400'
                         }`}>
                           KSh {landlord.pendingPayout.toLocaleString()}
                         </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${
                          landlord.advancePaid > 0 
                           ? 'bg-green-100 text-green-700 dark:bg-green-900/30' 
                           : 'bg-slate-100 text-slate-400'
                        }`}>
                          KSh {landlord.advancePaid.toLocaleString()}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                         <button className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 group-hover:text-slate-900 dark:group-hover:text-white transition-colors">
                           <ChevronRight size={20} />
                         </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
