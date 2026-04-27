'use client';

import { useParams, useRouter } from 'next/navigation';
import DashboardLayout from '@/components/DashboardLayout';
import { mockLandlords, mockProperties } from '@/lib/mock-data';
import { 
  ArrowLeft, Mail, Phone, MapPin, Building2, 
  DollarSign, TrendingUp, History, CreditCard,
  Edit, Plus, User, FileText, ChevronRight,
  Wallet, Landmark, Receipt, ExternalLink,
  Printer, X, CheckCircle2
} from 'lucide-react';
import { useState } from 'react';
import { LandlordPayout } from '@/lib/mock-data';

export default function LandlordProfilePage() {
  const params = useParams();
  const router = useRouter();
  const landlordId = params.id as string;
  
  const landlord = mockLandlords.find(l => l.id === landlordId) || mockLandlords[0];
  const landlordProperties = mockProperties.filter(p => landlord.propertyIds.includes(p.id));
  
  const [activeTab, setActiveTab] = useState<'overview' | 'financials' | 'properties'>('overview');
  const [selectedPayout, setSelectedPayout] = useState<LandlordPayout | null>(null);
  const [isVoucherOpen, setIsVoucherOpen] = useState(false);

  if (!landlord) return <div className="p-8 text-center text-slate-500">Landlord not found</div>;

  const handleViewVoucher = (payout: LandlordPayout) => {
    setSelectedPayout(payout);
    setIsVoucherOpen(true);
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Actions */}
        <div className="flex items-center justify-between">
          <button 
            onClick={() => router.push('/landlords')}
            className="flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
          >
            <ArrowLeft size={16} /> Back to Landlords
          </button>
          <div className="flex gap-3">
            <button className="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl text-sm font-bold hover:bg-slate-200 transition-colors flex items-center gap-2">
              <Edit size={16} /> Edit Profile
            </button>
            <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-sm font-bold hover:opacity-90 transition-opacity flex items-center gap-2">
              <Plus size={16} /> New Property
            </button>
          </div>
        </div>

        {/* Profile Card */}
        <div className="glass-card p-8 flex flex-col md:flex-row gap-8 items-start">
          <div className="w-32 h-32 rounded-3xl bg-slate-100 dark:bg-slate-800 overflow-hidden shrink-0 border-4 border-white dark:border-slate-800 shadow-xl">
             {landlord.profileImage ? (
                <img src={landlord.profileImage} alt={landlord.name} className="w-full h-full object-cover" />
             ) : (
                <div className="w-full h-full flex items-center justify-center text-slate-300">
                  <User size={64} />
                </div>
             )}
          </div>
          <div className="flex-1 space-y-4">
             <div>
               <h1 className="text-3xl font-black text-slate-900 dark:text-white">{landlord.name}</h1>
               <p className="text-slate-500 font-bold flex items-center gap-2 mt-1">
                 <Landmark size={14} className="text-accent-gold" /> Landlord Account
               </p>
             </div>
             
             <div className="flex flex-wrap gap-x-8 gap-y-3">
               <div className="flex items-center gap-2 text-sm font-bold text-slate-600 dark:text-slate-400">
                 <Mail size={16} className="text-slate-400" /> {landlord.email}
               </div>
               <div className="flex items-center gap-2 text-sm font-bold text-slate-600 dark:text-slate-400">
                 <Phone size={16} className="text-slate-400" /> {landlord.phone}
               </div>
             </div>

             <div className="p-4 bg-slate-50 dark:bg-slate-950/50 rounded-2xl border border-slate-100 dark:border-slate-800 inline-flex flex-col gap-1">
                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payout Account</p>
                <div className="flex items-center gap-2">
                  <span className="text-sm font-black text-slate-900 dark:text-white">{landlord.payoutAccount.bankName}</span>
                  <span className="text-xs text-slate-500 font-mono">{landlord.payoutAccount.accountNumber}</span>
                </div>
                <p className="text-[10px] font-bold text-slate-500">{landlord.payoutAccount.accountName}</p>
             </div>
          </div>

          <div className="flex flex-col gap-3 w-full md:w-auto">
             <div className="bg-slate-900 dark:bg-slate-50 p-6 rounded-3xl text-white dark:text-slate-900 shadow-2xl">
               <p className="text-[10px] font-black opacity-60 uppercase tracking-widest mb-1">Total Earned</p>
               <h3 className="text-3xl font-black">KSh {landlord.totalEarned.toLocaleString()}</h3>
             </div>
             <button className="flex items-center justify-center gap-2 py-3 bg-accent-gold text-slate-900 rounded-2xl font-black text-sm uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all">
               <DollarSign size={18} /> Process Payout
             </button>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex gap-1 border-b border-slate-200 dark:border-slate-800">
          {[
            { id: 'overview', label: 'Dashboard', icon: Wallet },
            { id: 'properties', label: `Portfolio (${landlordProperties.length})`, icon: Building2 },
            { id: 'financials', label: 'Payout History', icon: History },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={`flex items-center gap-2 px-6 py-4 text-sm font-bold border-b-2 transition-all ${
                activeTab === tab.id 
                  ? 'border-accent-gold text-accent-gold' 
                  : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'
              }`}
            >
              <tab.icon size={16} /> {tab.label}
            </button>
          ))}
        </div>

        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
          {activeTab === 'overview' && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-6">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                  <div className="glass-card p-6 bg-linear-to-br from-white to-amber-50 dark:from-slate-900 dark:to-slate-900">
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Pending Wallet</p>
                    <h3 className="text-4xl font-black text-slate-900 dark:text-white mb-2">KSh {landlord.pendingPayout.toLocaleString()}</h3>
                    <p className="text-xs text-slate-500 font-bold mb-6">Unpaid earnings from active rentals</p>
                    <button className="w-full py-3 bg-white dark:bg-slate-800 border-2 border-accent-gold/20 text-accent-gold rounded-xl font-black text-xs uppercase tracking-widest hover:bg-accent-gold hover:text-white transition-all">
                      Request Payout
                    </button>
                  </div>
                  <div className="glass-card p-6 bg-linear-to-br from-white to-green-50 dark:from-slate-900 dark:to-slate-900">
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Advance Paid</p>
                    <h3 className="text-4xl font-black text-slate-900 dark:text-white mb-2">KSh {landlord.advancePaid.toLocaleString()}</h3>
                    <p className="text-xs text-slate-500 font-bold mb-6">Outstanding advance balance</p>
                    <button className="w-full py-3 bg-white dark:bg-slate-800 border-2 border-green-500/20 text-green-500 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-green-500 hover:text-white transition-all">
                      Pay New Advance
                    </button>
                  </div>
                </div>

                <div className="glass-card p-6">
                  <h3 className="text-lg font-black mb-6">Recent Property Performance</h3>
                  <div className="space-y-4">
                     {landlordProperties.length > 0 ? landlordProperties.map(prop => (
                       <div key={prop.id} className="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 group hover:border-accent-gold/30 transition-all">
                         <div className="flex items-center gap-4">
                           <div className="w-12 h-12 rounded-xl overflow-hidden bg-slate-200">
                             <img src={prop.images[0]} alt={prop.title} className="w-full h-full object-cover" />
                           </div>
                           <div>
                             <p className="font-bold text-slate-900 dark:text-white">{prop.title}</p>
                             <p className="text-xs text-slate-500">{prop.location}</p>
                           </div>
                         </div>
                         <div className="text-right">
                           <p className="text-sm font-black text-slate-900 dark:text-white">KSh {prop.price.toLocaleString()}</p>
                           <p className="text-[10px] font-black text-green-500 uppercase tracking-widest">{prop.status}</p>
                         </div>
                         <button onClick={() => router.push(`/properties/${prop.id}`)} className="p-2 text-slate-400 hover:text-accent-gold">
                            <ExternalLink size={18} />
                         </button>
                       </div>
                     )) : (
                       <div className="text-center py-8 text-slate-500">
                          No properties listed yet.
                       </div>
                     )}
                  </div>
                </div>
              </div>

              <div className="space-y-6">
                <div className="glass-card p-6">
                  <h3 className="text-lg font-black mb-6">Quick Stats</h3>
                  <div className="space-y-4">
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Managed Since</span>
                      <span className="text-sm font-black text-slate-900 dark:text-white">Jan 2024</span>
                    </div>
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Commission Rate</span>
                      <span className="text-sm font-black text-slate-900 dark:text-white">10%</span>
                    </div>
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Occupancy Rate</span>
                      <span className="text-sm font-black text-green-500">92%</span>
                    </div>
                  </div>
                </div>

                <div className="glass-card p-6 bg-slate-900 text-white">
                   <h3 className="text-lg font-black mb-4">Portfolio Value</h3>
                   <div className="flex items-baseline gap-2 mb-2">
                     <span className="text-4xl font-black">KSh 4.2M</span>
                     <span className="text-xs font-bold text-green-400 font-mono tracking-tighter flex items-center gap-1">
                       <TrendingUp size={12} /> +12%
                     </span>
                   </div>
                   <p className="text-xs opacity-60 font-medium">Estimated total asset value of managed properties.</p>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'properties' && (
            <div className="glass-card p-0 overflow-hidden">
               <div className="p-6 border-b border-slate-100 dark:border-slate-800">
                 <h3 className="text-lg font-black">Owned Properties</h3>
               </div>
               <div className="overflow-x-auto">
                 <table className="w-full text-left border-collapse">
                   <thead>
                     <tr className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                       <th className="px-6 py-4">Property</th>
                       <th className="px-6 py-4">Status</th>
                       <th className="px-6 py-4">Units</th>
                       <th className="px-6 py-4">Expected Income</th>
                       <th className="px-6 py-4"></th>
                     </tr>
                   </thead>
                   <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {landlordProperties.map(prop => (
                        <tr key={prop.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group cursor-pointer" onClick={() => router.push(`/properties/${prop.id}`)}>
                          <td className="px-6 py-4">
                            <p className="font-bold text-slate-900 dark:text-white">{prop.title}</p>
                            <p className="text-xs text-slate-500">{prop.location}</p>
                          </td>
                          <td className="px-6 py-4">
                            <span className="px-3 py-1 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded-full text-[10px] font-black uppercase tracking-widest">{prop.status}</span>
                          </td>
                          <td className="px-6 py-4 font-bold text-slate-700 dark:text-slate-300">
                            {prop.units.length} Units
                          </td>
                          <td className="px-6 py-4 font-black">KSh {prop.price.toLocaleString()}</td>
                          <td className="px-6 py-4 text-right">
                             <ChevronRight size={18} className="text-slate-300 group-hover:text-accent-gold transition-colors" />
                          </td>
                        </tr>
                      ))}
                   </tbody>
                 </table>
               </div>
            </div>
          )}

          {activeTab === 'financials' && (
            <div className="glass-card p-0 overflow-hidden">
               <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                 <h3 className="text-lg font-black">Statement of Account</h3>
                 <button className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors">
                    <Receipt size={16} /> Export CSV
                 </button>
               </div>
               <div className="overflow-x-auto">
                 <table className="w-full text-left border-collapse">
                   <thead>
                     <tr className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                       <th className="px-6 py-4">Reference</th>
                       <th className="px-6 py-4">Date</th>
                       <th className="px-6 py-4">Type</th>
                       <th className="px-6 py-4">Period</th>
                       <th className="px-6 py-4">Amount</th>
                       <th className="px-6 py-4">Status</th>
                       <th className="px-6 py-4">Actions</th>
                     </tr>
                   </thead>
                   <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-sm font-semibold text-slate-700 dark:text-slate-300">
                      {landlord.payouts.map((payout) => (
                        <tr key={payout.id} className="hover:bg-slate-50 dark:hover:bg-slate-900/50 group transition-colors">
                          <td className="px-6 py-4 font-mono text-xs">{payout.reference}</td>
                          <td className="px-6 py-4">{new Date(payout.date).toLocaleDateString()}</td>
                          <td className="px-6 py-4">{payout.type}</td>
                          <td className="px-6 py-4 text-xs font-bold text-slate-400">{payout.period}</td>
                          <td className="px-6 py-4 font-black text-slate-900 dark:text-white">KSh {payout.amount.toLocaleString()}</td>
                          <td className="px-6 py-4">
                            <span className="px-3 py-1 bg-green-100 text-green-700 dark:bg-green-900/30 rounded-full text-[10px] font-black uppercase tracking-widest">{payout.status}</span>
                          </td>
                          <td className="px-6 py-4">
                             <button 
                               onClick={() => handleViewVoucher(payout)}
                               className="p-2 text-slate-400 hover:text-accent-gold hover:bg-white dark:hover:bg-slate-800 rounded-xl transition-all shadow-sm flex items-center gap-2"
                             >
                               <Receipt size={18} />
                               <span className="text-[10px] uppercase font-black tracking-tighter opacity-0 group-hover:opacity-100 transition-opacity">Voucher</span>
                             </button>
                          </td>
                        </tr>
                      ))}
                   </tbody>
                 </table>
               </div>
            </div>
          )}
        </div>
      </div>

      {/* Payout Voucher Modal */}
      {isVoucherOpen && selectedPayout && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-slate-950 w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            {/* Modal Header (Hidden on Print) */}
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center print:hidden">
              <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                <Receipt size={24} className="text-accent-gold" /> Payout Voucher
              </h3>
              <div className="flex items-center gap-3">
                 <button 
                   onClick={() => window.print()}
                   className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2 hover:opacity-90 transition-opacity"
                 >
                   <Printer size={16} /> Print Voucher
                 </button>
                 <button 
                   onClick={() => setIsVoucherOpen(false)}
                   className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl text-slate-400 transition-colors"
                 >
                   <X size={24} />
                 </button>
              </div>
            </div>

            {/* Voucher Content */}
            <div className="p-12 overflow-y-auto print:p-0 print:overflow-visible flex-1" id="voucher-content">
              <div className="flex flex-col gap-12">
                {/* Brand Header */}
                <div className="flex justify-between items-start">
                  <div>
                    <div className="flex items-center gap-2 mb-2 font-black text-2xl tracking-tighter text-slate-900 dark:text-white">
                      <div className="w-10 h-10 bg-slate-900 dark:bg-white rounded-xl flex items-center justify-center text-white dark:text-slate-900 shadow-lg">
                        <Building2 size={24} />
                      </div>
                      PRIMELINK
                    </div>
                    <p className="text-xs font-bold text-slate-500 uppercase tracking-widest">Management System</p>
                  </div>
                  <div className="text-right">
                    <h2 className="text-2xl font-black uppercase tracking-tighter mb-1 select-none">PAYMENT VOUCHER</h2>
                    <p className="font-mono text-sm text-slate-400">Ref: {selectedPayout.reference}</p>
                    <p className="text-sm font-bold mt-1 text-slate-700 dark:text-slate-300">Date: {new Date(selectedPayout.date).toLocaleDateString()}</p>
                  </div>
                </div>

                {/* Landlord & Bank Details */}
                <div className="grid grid-cols-2 gap-8 p-8 bg-slate-50 dark:bg-slate-900 rounded-3xl border border-slate-100 dark:border-slate-800">
                  <div className="space-y-4">
                     <div>
                       <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Payee Details</p>
                       <p className="text-lg font-black text-slate-900 dark:text-white">{landlord.name}</p>
                       <p className="text-sm font-medium text-slate-500">{landlord.email}</p>
                       <p className="text-sm font-medium text-slate-500">{landlord.phone}</p>
                     </div>
                  </div>
                  <div className="space-y-4 border-l border-slate-200 dark:border-slate-700 pl-8">
                     <div>
                       <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Bank Information</p>
                       <p className="text-sm font-black text-slate-900 dark:text-white uppercase">{landlord.payoutAccount.bankName}</p>
                       <p className="text-lg font-mono font-bold text-slate-700 dark:text-slate-300">***{landlord.payoutAccount.accountNumber.slice(-4)}</p>
                       <p className="text-xs font-bold text-slate-500">{landlord.payoutAccount.accountName}</p>
                     </div>
                  </div>
                </div>

                {/* Financial Breakdown */}
                <div className="space-y-6">
                  <div className="flex items-center gap-3 border-b-2 border-slate-900 dark:border-white pb-2 mb-4">
                    <History size={18} className="text-accent-gold" />
                    <h4 className="font-black uppercase tracking-widest text-xs">Payout Breakdown ({selectedPayout.period})</h4>
                  </div>
                  
                  <div className="space-y-4">
                    <div className="flex justify-between items-center text-sm">
                       <span className="font-bold text-slate-500">Gross Rental Collection</span>
                       <span className="font-black text-slate-900 dark:text-white">KSh {selectedPayout.breakdown.grossCollection.toLocaleString()}</span>
                    </div>
                    <div className="flex justify-between items-center text-sm">
                       <span className="font-bold text-slate-500">Management Commission (10%)</span>
                       <span className="font-black text-red-500">- KSh {selectedPayout.breakdown.managementFee.toLocaleString()}</span>
                    </div>
                    <div className="flex justify-between items-center text-sm">
                       <span className="font-bold text-slate-500">WHT / Statutory Deductions</span>
                       <span className="font-black text-red-500">- KSh {selectedPayout.breakdown.tax.toLocaleString()}</span>
                    </div>
                    
                    <div className="w-full h-px bg-slate-100 dark:bg-slate-800 my-2"></div>
                    
                    <div className="flex justify-between items-center p-6 bg-slate-900 dark:bg-slate-50 rounded-2xl text-white dark:text-slate-900">
                       <div>
                         <p className="text-[10px] font-black opacity-60 uppercase tracking-widest">Net Payable Amount</p>
                         <p className="text-xs font-bold opacity-40">Direct Bank Transfer</p>
                       </div>
                       <h3 className="text-3xl font-black">KSh {selectedPayout.breakdown.netPayout.toLocaleString()}</h3>
                    </div>
                  </div>
                </div>

                {/* Status & Footer */}
                <div className="flex justify-between items-end pt-12">
                   <div className="space-y-6">
                     <div className="flex items-center gap-3 p-3 bg-green-50 dark:bg-green-900/20 text-green-600 rounded-xl border border-green-100 dark:border-green-900/30">
                        <CheckCircle2 size={24} />
                        <div>
                          <p className="text-[10px] font-black uppercase tracking-widest">Payment Status</p>
                          <p className="text-sm font-black uppercase tracking-tighter">SUCCESSFULLY PROCESSED</p>
                        </div>
                     </div>
                     <div className="flex gap-8 print:hidden">
                        <div>
                          <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Authorized Signature</p>
                          <div className="w-32 h-px bg-slate-300 dark:bg-slate-700"></div>
                        </div>
                        <div>
                          <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Payee Signature</p>
                          <div className="w-32 h-px bg-slate-300 dark:bg-slate-700"></div>
                        </div>
                     </div>
                   </div>
                   <div className="text-right hidden print:block">
                      <p className="text-[8px] font-black text-slate-400 uppercase tracking-widest">Digital Audit ID</p>
                      <p className="text-[10px] font-mono text-slate-300 truncate w-48 ml-auto">{selectedPayout.id}-{Date.now()}</p>
                   </div>
                </div>
              </div>
            </div>

            {/* Print Styles */}
            <style jsx global>{`
              @media print {
                body * {
                  visibility: hidden;
                }
                #voucher-content, #voucher-content * {
                  visibility: visible;
                }
                #voucher-content {
                  position: absolute;
                  left: 0;
                  top: 0;
                  width: 100%;
                  padding: 40px !important;
                }
                .glass-card {
                  border: 1px solid #e2e8f0 !important;
                  background: white !important;
                }
                .dark {
                   display: none !important;
                }
              }
            `}</style>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
