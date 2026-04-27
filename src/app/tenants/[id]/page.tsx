'use client';

import { useParams, useRouter } from 'next/navigation';
import DashboardLayout from '@/components/DashboardLayout';
import { mockTenants } from '@/lib/mock-data';
import { 
  ArrowLeft, 
  User, 
  Mail, 
  Phone, 
  MapPin, 
  Calendar, 
  DollarSign, 
  FileText, 
  Edit, 
  CreditCard,
  Download,
  AlertCircle,
  Building2,
  Printer,
  CheckCircle,
  X
} from 'lucide-react';
import { useState } from 'react';

// Mock Transaction History
const mockTransactions = [
  { id: 'tx-1', date: '2024-03-01', amount: 4500, type: 'Rent Payment', status: 'Paid', method: 'Bank Transfer' },
  { id: 'tx-2', date: '2024-02-01', amount: 4500, type: 'Rent Payment', status: 'Paid', method: 'Bank Transfer' },
  { id: 'tx-3', date: '2024-01-15', amount: 500, type: 'Security Deposit', status: 'Paid', method: 'Credit Card' },
];

// Mock Pending Balances
const mockBalances = [
  { id: 'bal-1', description: 'March Utilities (Water & Electricity)', amount: 12500, dueDate: '2024-03-15', type: 'Utility' },
  { id: 'bal-2', description: 'Late Payment Fee (February)', amount: 2500, dueDate: '2024-03-10', type: 'Fee' },
];

export default function TenantDetailPage() {
  const params = useParams();
  const router = useRouter();
  const tenantId = params.id as string;
  
  const tenant = mockTenants.find(t => t.id === tenantId) || mockTenants[0]; // Fallback for preview
  
  const [activeTab, setActiveTab] = useState<'profile' | 'financials' | 'documents'>('profile');
  const [selectedTransaction, setSelectedTransaction] = useState<any>(null);

  if (!tenant) return <div className="p-8 text-center text-slate-500">Tenant not found</div>;

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Actions */}
        <div className="flex items-center justify-between">
          <button 
            onClick={() => router.push('/tenants')}
            className="flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
          >
            <ArrowLeft size={16} /> Back to Tenants
          </button>
          <div className="flex gap-3">
            <button className="px-4 py-2 border border-slate-200 dark:border-slate-800 rounded-xl text-sm font-bold hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors flex items-center gap-2">
              <Mail size={16} /> Send Message
            </button>
            <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-sm font-bold hover:opacity-90 transition-opacity flex items-center gap-2">
              <CreditCard size={16} /> Record Payment
            </button>
          </div>
        </div>

        {/* Profile Header Card */}
        <div className="glass-card p-8 flex flex-col md:flex-row items-start md:items-center gap-6 relative overflow-hidden">
          <div className="absolute top-0 right-0 w-64 h-64 bg-accent-gold/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
          
          <img 
            src={tenant.profileImage || 'https://images.unsplash.com/photo-1633332755192-727a05c4013d?q=80&w=200'} 
            alt={tenant.name} 
            className="w-24 h-24 rounded-full object-cover border-4 border-white dark:border-slate-800 shadow-xl z-10"
          />
          
          <div className="flex-1 z-10">
            <div className="flex items-center gap-3 mb-1">
              <h2 className="text-3xl font-black text-slate-900 dark:text-white">{tenant.name}</h2>
              <span className={`px-3 py-1 rounded-full text-xs font-semibold ${
                tenant.status === 'Active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30' : 
                tenant.status === 'Pending' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30' : 
                'bg-red-100 text-red-700 dark:bg-red-900/30'
              }`}>
                {tenant.status}
              </span>
            </div>
            <div className="flex flex-wrap gap-4 text-sm text-slate-500 font-medium">
              <p className="flex items-center gap-1.5"><Mail size={14} /> {tenant.email}</p>
              <p className="flex items-center gap-1.5"><Phone size={14} /> {tenant.phone}</p>
              <p className="flex items-center gap-1.5"><MapPin size={14} /> {tenant.propertyName} {tenant.unit ? `- Unit ${tenant.unit}` : ''}</p>
            </div>
          </div>
        </div>

        {/* Navigation Tabs */}
        <div className="flex border-b border-slate-200 dark:border-slate-800">
          {[
            { id: 'profile', label: 'Profile Details', icon: User },
            { id: 'financials', label: 'Financial Records', icon: DollarSign },
            { id: 'documents', label: 'Lease & Documents', icon: FileText },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={`flex items-center gap-2 px-6 py-4 text-sm font-bold border-b-2 transition-colors ${
                activeTab === tab.id 
                  ? 'border-accent-gold text-accent-gold' 
                  : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'
              }`}
            >
              <tab.icon size={16} /> {tab.label}
            </button>
          ))}
        </div>

        {/* Tab Content */}
        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
          
          {/* PROFILE DETAILS TAB */}
          {activeTab === 'profile' && (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div className="glass-card p-6">
                <div className="flex items-center justify-between mb-6">
                  <h3 className="text-lg font-bold">Personal Information</h3>
                  <button className="text-accent-gold hover:underline text-sm font-bold flex items-center gap-1">
                    <Edit size={14} /> Edit
                  </button>
                </div>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Full Name</p>
                      <p className="font-semibold text-slate-900 dark:text-white">{tenant.name}</p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">National ID</p>
                      <p className="font-semibold text-slate-900 dark:text-white font-mono">{tenant.nationalId || 'N/A'}</p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Email</p>
                      <p className="font-semibold text-slate-900 dark:text-white">{tenant.email}</p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Phone</p>
                      <p className="font-semibold text-slate-900 dark:text-white">{tenant.phone}</p>
                    </div>
                  </div>
                </div>
              </div>

              <div className="glass-card p-6">
                <div className="flex items-center justify-between mb-6">
                  <h3 className="text-lg font-bold">Lease Information</h3>
                  <button className="text-accent-gold hover:underline text-sm font-bold flex items-center gap-1">
                    <Edit size={14} /> Update
                  </button>
                </div>
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Assigned Property</p>
                      <p className="font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                        <Building2 size={16} className="text-accent-gold" />
                        {tenant.propertyName}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Unit/House</p>
                      <p className="font-semibold text-slate-900 dark:text-white">{tenant.unit || 'N/A'}</p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Monthly Rent</p>
                      <p className="font-semibold text-slate-900 dark:text-white">KSh {tenant.rentAmount.toLocaleString()}</p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Lease Start</p>
                      <p className="font-semibold text-slate-900 dark:text-white flex items-center gap-1.5">
                        <Calendar size={14} className="text-slate-400" />
                        {new Date(tenant.leaseStart).toLocaleDateString()}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs uppercase tracking-widest text-slate-400 font-bold mb-1">Lease End</p>
                      <p className="font-semibold text-slate-900 dark:text-white flex items-center gap-1.5">
                        <Calendar size={14} className="text-slate-400" />
                        {new Date(tenant.leaseEnd).toLocaleDateString()}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* FINANCIALS TAB */}
          {activeTab === 'financials' && (
            <div className="space-y-6">
              {/* Pending Balances */}
              <div className="glass-card overflow-hidden border-amber-500/30">
                <div className="p-6 border-b border-slate-100 dark:border-slate-800 bg-amber-50/50 dark:bg-amber-900/10 flex items-center justify-between">
                  <h3 className="text-lg font-bold flex items-center gap-2 text-amber-700 dark:text-amber-500">
                    <AlertCircle size={20} /> Pending Balances
                  </h3>
                  <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-sm font-bold hover:opacity-90 transition-opacity">
                    Generate Invoice
                  </button>
                </div>
                {mockBalances.length > 0 ? (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left">
                      <thead>
                        <tr className="bg-slate-50 dark:bg-slate-900/50">
                          <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</th>
                          <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                          <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</th>
                          <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                          <th className="px-6 py-3"></th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {mockBalances.map((bal) => (
                          <tr key={bal.id}>
                            <td className="px-6 py-4 font-semibold text-slate-900 dark:text-white">{bal.description}</td>
                            <td className="px-6 py-4 text-sm text-slate-500">{bal.type}</td>
                            <td className="px-6 py-4 text-sm font-medium text-red-500 flex items-center gap-1.5">
                              <Calendar size={14} /> {new Date(bal.dueDate).toLocaleDateString()}
                            </td>
                            <td className="px-6 py-4 font-black">KSh {bal.amount.toLocaleString()}</td>
                            <td className="px-6 py-4 text-right">
                              <button className="text-xs font-bold text-accent-gold hover:underline">Mark Paid</button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <div className="p-8 text-center text-slate-500 font-medium">No pending balances.</div>
                )}
              </div>

              {/* Transaction History */}
              <div className="glass-card overflow-hidden">
                <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                  <h3 className="text-lg font-bold">Payment History</h3>
                  <button className="text-sm font-bold flex items-center gap-2 text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors">
                    <Download size={16} /> Export CSV
                  </button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead>
                      <tr className="bg-slate-50 dark:bg-slate-900/50">
                        <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Method</th>
                        <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th className="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th className="px-6 py-3"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {mockTransactions.map((tx) => (
                        <tr key={tx.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors">
                          <td className="px-6 py-4 text-sm font-medium text-slate-500">{new Date(tx.date).toLocaleDateString()}</td>
                          <td className="px-6 py-4 font-semibold text-slate-900 dark:text-white">{tx.type}</td>
                          <td className="px-6 py-4 text-sm text-slate-500">{tx.method}</td>
                          <td className="px-6 py-4">
                            <span className="px-2 py-1 bg-green-100 text-green-700 dark:bg-green-900/30 text-xs font-semibold rounded-md">
                              {tx.status}
                            </span>
                          </td>
                          <td className="px-6 py-4 font-black">KSh {tx.amount.toLocaleString()}</td>
                          <td className="px-6 py-4 text-right">
                            <button 
                              onClick={() => setSelectedTransaction(tx)}
                              className="text-slate-400 hover:text-accent-gold transition-colors"
                              title="View Receipt"
                            >
                              <FileText size={16} />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {/* DOCUMENTS TAB */}
          {activeTab === 'documents' && (
            <div className="glass-card p-6 min-h-[400px] flex flex-col items-center justify-center text-center space-y-4">
              <div className="w-20 h-20 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center text-slate-400 mb-4">
                <FileText size={32} />
              </div>
              <h3 className="text-xl font-bold">Lease & Identification Documents</h3>
              <p className="text-slate-500 max-w-md">
                Upload and manage the tenant's signed lease agreements, National ID copies, and other important documents here.
              </p>
              <button className="mt-4 px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold shadow-lg hover:translate-y-[-2px] transition-all flex items-center gap-2">
                <FileText size={18} /> Upload Document
              </button>
            </div>
          )}
          
        </div>

        {/* RECEIPT MODAL */}
        {selectedTransaction && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 print-hidden">
            <div id="receipt-modal" className="glass-card w-full max-w-md bg-white dark:bg-slate-900 shadow-2xl overflow-hidden flex flex-col relative">
              
              {/* Receipt Header Overlay */}
              <div className="p-4 flex justify-between items-center absolute top-0 w-full z-10 bg-linear-to-b from-white dark:from-slate-900 to-transparent print-hidden">
                  <div />
                  <button 
                    onClick={() => setSelectedTransaction(null)}
                    className="p-2 transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100/80 dark:bg-slate-800/80 rounded-full shadow-sm"
                  >
                    <X size={18} />
                  </button>
              </div>

              {/* Receipt Content */}
              <div className="p-8 pt-12 text-center space-y-6">
                <div className="mx-auto w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center text-green-600 dark:text-green-500 mb-2">
                  <CheckCircle size={32} />
                </div>
                
                <h3 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-wider">Payment Receipt</h3>
                
                <div className="py-6 border-y border-slate-100 dark:border-slate-800 border-dashed space-y-4 text-left">
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-slate-500 font-bold uppercase tracking-wider">Date paid</span>
                    <span className="font-semibold text-slate-900 dark:text-white">{new Date(selectedTransaction.date).toLocaleDateString()}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-slate-500 font-bold uppercase tracking-wider">Receipt No.</span>
                    <span className="font-mono font-semibold text-slate-900 dark:text-white">RCPT-{selectedTransaction.id.split('-')[1]}-2024</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-slate-500 font-bold uppercase tracking-wider">Payment Method</span>
                    <span className="font-semibold text-slate-900 dark:text-white">{selectedTransaction.method}</span>
                  </div>
                  <div className="flex justify-between items-center">
                    <span className="text-sm text-slate-500 font-bold uppercase tracking-wider">For</span>
                    <span className="font-semibold text-slate-900 dark:text-white">{selectedTransaction.type}</span>
                  </div>
                </div>

                <div className="pt-2">
                  <span className="text-sm text-slate-500 font-bold uppercase tracking-wider block mb-1">Amount Paid</span>
                  <p className="text-4xl font-black text-slate-900 dark:text-white">
                    KSh {selectedTransaction.amount.toLocaleString()}
                  </p>
                </div>
                
                <p className="text-xs text-slate-400 font-medium pb-4">Billed to: {tenant.name} <br/> Property: {tenant.propertyName}</p>
              </div>

              {/* Modal Actions */}
              <div className="p-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 grid grid-cols-2 gap-4 print-hidden">
                <button 
                  onClick={() => window.print()}
                  className="py-3 text-sm font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 transition-colors flex items-center justify-center gap-2 border border-slate-200 dark:border-slate-800 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                  <Download size={16} /> Download
                </button>
                <button 
                  onClick={() => window.print()}
                  className="py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-sm uppercase tracking-widest shadow-xl hover:translate-y-[-2px] transition-all flex items-center justify-center gap-2"
                >
                  <Printer size={16} /> Print
                </button>
              </div>
            </div>
          </div>
        )}

      </div>
    </DashboardLayout>
  );
}
