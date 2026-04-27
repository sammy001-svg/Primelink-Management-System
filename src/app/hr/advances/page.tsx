'use client';

import { useState, useRef } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockAdvances as initialAdvances, mockEmployees, EmployeeAdvance } from '@/lib/mock-data';
import { 
  HandCoins, 
  Search, 
  Plus, 
  MoreVertical,
  CheckCircle,
  Clock,
  Calendar,
  X,
  User,
  History,
  TrendingDown,
  Printer,
  FileText,
  Check,
  Ban,
  DollarSign,
  ArrowRight,
  Hash
} from 'lucide-react';

export default function AdvancesPage() {
  const [advances, setAdvances] = useState<EmployeeAdvance[]>(initialAdvances);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState<string>('All');
  const [showRequestModal, setShowRequestModal] = useState(false);
  const [viewingReceipt, setViewingReceipt] = useState<EmployeeAdvance | null>(null);

  // Form State
  const [formData, setFormData] = useState({
    employeeId: '',
    amount: '',
    repaymentPeriod: '3',
  });

  // Generate Receipt Number
  const generateReceiptNumber = () => {
    return `ADV-RCPT-${Date.now().toString().slice(-6)}`;
  };

  const handleRequestAdvance = (e: React.FormEvent) => {
    e.preventDefault();
    const employee = mockEmployees.find(emp => emp.id === formData.employeeId);
    if (!employee) return;

    const newAdvance: EmployeeAdvance = {
      id: `adv-${Date.now().toString().slice(-4)}`,
      employeeId: employee.id,
      employeeName: employee.name,
      amount: Number(formData.amount),
      dateRequested: new Date().toISOString().split('T')[0],
      repaymentPeriod: Number(formData.repaymentPeriod),
      status: 'Pending',
      balance: Number(formData.amount)
    };

    setAdvances([newAdvance, ...advances]);
    setShowRequestModal(false);
    setFormData({ employeeId: '', amount: '', repaymentPeriod: '3' });
  };

  const updateAdvanceStatus = (id: string, newStatus: 'Approved' | 'Rejected') => {
    setAdvances(advances.map(a => a.id === id ? { ...a, status: newStatus as any } : a));
  };

  const handlePrint = () => {
    window.print();
  };

  // Filtering
  const filteredAdvances = advances.filter(adv => {
    const matchesSearch = adv.employeeName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          adv.id.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'All' || adv.status === filterStatus;
    
    return matchesSearch && matchesStatus;
  });

  const totalOutstanding = advances.reduce((sum, adv) => adv.status === 'Approved' ? sum + adv.balance : sum, 0);

  const statusColors: Record<string, string> = {
    'Approved': 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'Pending': 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    'Fully Repaid': 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    'Rejected': 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6 print:hidden">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Employee Advances</h1>
            <p className="text-slate-500 mt-1 font-medium">Manage and track salary advances, repayments, and outstanding balances.</p>
          </div>
          <button 
            onClick={() => setShowRequestModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
          >
            <Plus size={18} /> New Request
          </button>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-accent-gold hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-accent-gold/10 rounded-2xl flex items-center justify-center text-accent-gold">
                <HandCoins size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Total Outstanding</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {totalOutstanding.toLocaleString()}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-orange-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-orange-500/10 rounded-2xl flex items-center justify-center text-orange-500">
                <Clock size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Pending Approval</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {advances.filter(a => a.status === 'Pending').length}
                </h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-blue-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                <History size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Repaid Requests</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {advances.filter(a => a.status === 'Fully Repaid').length}
                </h3>
              </div>
            </div>
          </div>
        </div>

        {/* Filters Box */}
        <div className="glass-card p-4 flex flex-col md:flex-row gap-4 items-center justify-between">
          <div className="relative w-full md:w-96 group">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
            <input 
              type="text" 
              placeholder="Search by employee name or ID..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
          <div className="flex gap-4 w-full md:w-auto overflow-x-auto custom-scrollbar pb-2 md:pb-0">
            {['All', 'Approved', 'Pending', 'Rejected', 'Fully Repaid'].map(status => (
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

        {/* Advances Data Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[800px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Employee</th>
                  <th className="px-6 py-4">Amount Requested</th>
                  <th className="px-6 py-4">Date</th>
                  <th className="px-6 py-4">Repayment</th>
                  <th className="px-6 py-4 text-accent-gold">Balance</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredAdvances.length > 0 ? (
                  filteredAdvances.map((adv) => (
                    <tr 
                      key={adv.id} 
                      className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group"
                    >
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <User size={16} className="text-slate-400" />
                          <div>
                            <p className="font-bold text-slate-900 dark:text-white">{adv.employeeName}</p>
                            <p className="text-[10px] text-slate-500 font-mono uppercase tracking-widest italic">{adv.employeeId}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 font-black text-slate-900 dark:text-white">
                        KSh {adv.amount.toLocaleString()}
                      </td>
                      <td className="px-6 py-4 text-sm font-medium text-slate-600 dark:text-slate-400">
                        <div className="flex items-center gap-2">
                          <Calendar size={14} className="text-slate-400" />
                          {new Date(adv.dateRequested).toLocaleDateString()}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex flex-col">
                          <span className="text-sm font-bold text-slate-700 dark:text-slate-300">{adv.repaymentPeriod} Months</span>
                          <span className="text-[10px] text-slate-400 uppercase tracking-tighter">Installment Plan</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          {adv.status === 'Approved' ? (
                            <>
                              <div className="w-16 h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div 
                                  className="h-full bg-accent-gold transition-all" 
                                  style={{ width: `${((adv.amount - adv.balance) / adv.amount) * 100}%` }}
                                />
                              </div>
                              <span className="font-black text-accent-gold">KSh {adv.balance.toLocaleString()}</span>
                            </>
                          ) : (
                            <span className="text-slate-300">--</span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${statusColors[adv.status]}`}>
                          {adv.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                          {adv.status === 'Pending' && (
                            <>
                              <button 
                                onClick={() => updateAdvanceStatus(adv.id, 'Approved')}
                                className="p-2 bg-green-500/10 text-green-600 hover:bg-green-500 hover:text-white rounded-xl transition-all shadow-sm border border-green-500/20"
                                title="Approve Request"
                              >
                                <Check size={18} />
                              </button>
                              <button 
                                onClick={() => updateAdvanceStatus(adv.id, 'Rejected')}
                                className="p-2 bg-red-500/10 text-red-600 hover:bg-red-500 hover:text-white rounded-xl transition-all shadow-sm border border-red-500/20"
                                title="Reject Request"
                              >
                                <Ban size={18} />
                              </button>
                            </>
                          )}
                          {adv.status === 'Approved' && (
                            <button 
                              onClick={() => setViewingReceipt(adv)}
                              className="p-2 bg-white dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm border border-slate-100 dark:border-slate-700 flex items-center gap-2 group/btn"
                            >
                              <FileText size={18} />
                              <span className="text-[10px] font-black uppercase tracking-widest hidden group-hover/btn:block px-2">Receipt</span>
                            </button>
                          )}
                          <button className="p-2 bg-white dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm border border-slate-100 dark:border-slate-700">
                            <MoreVertical size={18} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500 bg-slate-50/50 dark:bg-slate-900/10">
                      <HandCoins size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                      <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No advances found</p>
                      <p className="text-slate-500">There are no advance records matching your current criteria.</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* New Request Modal */}
      {showRequestModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="glass-card max-w-2xl w-full p-8 relative my-8">
            <button 
              onClick={() => setShowRequestModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="flex items-center gap-4 mb-8">
              <div className="w-12 h-12 bg-accent-gold/10 text-accent-gold rounded-xl flex items-center justify-center">
                <HandCoins size={24} />
              </div>
              <div>
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight italic">NEW ADVANCE REQUEST</h2>
                <p className="text-slate-500 text-xs font-black uppercase tracking-widest">Employee Financial Support</p>
              </div>
            </div>

            <form onSubmit={handleRequestAdvance} className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2 md:col-span-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Select Employee</label>
                  <select 
                    required
                    value={formData.employeeId}
                    onChange={(e) => setFormData({...formData, employeeId: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  >
                    <option value="">Choose Employee...</option>
                    {mockEmployees.map(emp => (
                      <option key={emp.id} value={emp.id}>{emp.name} ({emp.employeeNumber})</option>
                    ))}
                  </select>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Advance Amount (KSh)</label>
                  <div className="relative">
                    <DollarSign className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input 
                      required
                      type="number" 
                      value={formData.amount}
                      onChange={(e) => setFormData({...formData, amount: e.target.value})}
                      className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-bold outline-none transition-all"
                      placeholder="e.g. 50000"
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Repayment Period</label>
                  <select 
                    value={formData.repaymentPeriod}
                    onChange={(e) => setFormData({...formData, repaymentPeriod: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  >
                    <option value="1">1 Month</option>
                    <option value="2">2 Months</option>
                    <option value="3">3 Months</option>
                    <option value="6">6 Months</option>
                    <option value="12">12 Months</option>
                  </select>
                </div>
              </div>

              <div className="pt-6 flex gap-4">
                <button 
                  type="button"
                  onClick={() => setShowRequestModal(false)}
                  className="flex-1 px-6 py-4 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button 
                  type="submit"
                  className="flex-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 transition-all"
                >
                  Submit Request
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Receipt View Modal */}
      {viewingReceipt && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="max-w-3xl w-full relative print:m-0 print:p-0">
             {/* Modal Controls (Hidden on Print) */}
             <div className="absolute -top-14 right-0 flex gap-3 print:hidden">
              <button 
                onClick={handlePrint}
                className="p-3 bg-white dark:bg-slate-900 text-slate-900 dark:text-white rounded-xl shadow-xl border border-slate-100 dark:border-slate-800 hover:scale-105 transition-all flex items-center gap-2 font-black text-xs uppercase tracking-widest"
              >
                <Printer size={18} /> Print Receipt
              </button>
              <button 
                onClick={() => setViewingReceipt(null)}
                className="p-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl shadow-xl hover:scale-105 transition-all"
              >
                <X size={18} />
              </button>
            </div>

            {/* Receipt Content */}
            <div className="bg-white dark:bg-slate-950 rounded-3xl p-10 shadow-3xl text-slate-900 dark:text-white min-h-[700px] border-2 border-slate-50 dark:border-slate-900 print:shadow-none print:border-none print:p-8">
              {/* Header */}
              <div className="flex justify-between items-start border-b-4 border-slate-900 dark:border-white pb-8 mb-10">
                <div>
                  <h2 className="text-3xl font-black tracking-tighter uppercase italic">PrimeLink</h2>
                  <p className="text-sm font-bold text-slate-500 uppercase tracking-widest">Management System</p>
                  <div className="mt-4 space-y-1 text-xs font-bold text-slate-500 uppercase tracking-wider">
                    <p>Riverside Drive, Nairobi</p>
                    <p>contact@primelink.com</p>
                  </div>
                </div>
                <div className="text-right">
                  <span className="px-4 py-2 bg-accent-gold text-white text-[10px] font-black uppercase tracking-[0.2em] rounded-lg shadow-lg shadow-accent-gold/20">
                    Advance Receipt
                  </span>
                  <p className="text-xs font-bold text-slate-400 mt-6 uppercase tracking-widest italic">Receipt No:</p>
                  <h3 className="text-xl font-black uppercase tracking-tight text-accent-gold">{generateReceiptNumber()}</h3>
                </div>
              </div>

              {/* Transaction Label */}
              <div className="text-center mb-12">
                <h4 className="text-4xl font-black tracking-tighter uppercase italic text-slate-900 dark:text-white">PAYMENT VOUCHER</h4>
                <div className="w-24 h-1 bg-accent-gold mx-auto mt-2 rounded-full"></div>
              </div>

              {/* Data Area */}
              <div className="space-y-8 mb-16">
                <div className="grid grid-cols-2 gap-10">
                  <div className="space-y-1">
                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Beneficiary Name</p>
                    <p className="text-xl font-black uppercase tracking-tight border-b-2 border-slate-50 dark:border-slate-900 pb-2">{viewingReceipt.employeeName}</p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Employee ID</p>
                    <p className="text-xl font-black uppercase tracking-tight border-b-2 border-slate-50 dark:border-slate-900 pb-2">
                       {mockEmployees.find(e => e.id === viewingReceipt.employeeId)?.employeeNumber || 'PL-EMP-N/A'}
                    </p>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-10">
                  <div className="space-y-1">
                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Payment Date</p>
                    <p className="text-xl font-black uppercase tracking-tight border-b-2 border-slate-50 dark:border-slate-900 pb-2">{new Date(viewingReceipt.dateRequested).toLocaleDateString()}</p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Repayment Period</p>
                    <p className="text-xl font-black uppercase tracking-tight border-b-2 border-slate-50 dark:border-slate-900 pb-2">{viewingReceipt.repaymentPeriod} MONTHS</p>
                  </div>
                </div>

                <div className="bg-slate-50 dark:bg-slate-900 rounded-2xl p-8 border-2 border-slate-100 dark:border-slate-800">
                  <p className="text-[10px] font-black uppercase tracking-[0.3em] text-slate-400 mb-2">Amount in Figures</p>
                  <div className="flex justify-between items-end">
                    <h5 className="text-5xl font-black tracking-tighter uppercase italic text-slate-900 dark:text-white">
                      KSh {viewingReceipt.amount.toLocaleString()}.00
                    </h5>
                    <div className="text-right">
                      <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 italic">monthly installment:</p>
                      <p className="text-lg font-black text-accent-gold">KSh {Math.round(viewingReceipt.amount / viewingReceipt.repaymentPeriod).toLocaleString()}.00</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Footer signatures */}
              <div className="grid grid-cols-2 gap-20 mt-auto pt-10 border-t border-slate-100 dark:border-slate-900">
                <div className="space-y-6 text-center">
                   <div className="h-12 border-b-2 border-slate-900 dark:border-white w-full opacity-20 italic font-medium flex items-end justify-center pb-1 text-slate-400">Internal Digital Sign</div>
                   <p className="text-[10px] font-black uppercase tracking-[0.2em]">Prepared by HR Dept</p>
                </div>
                <div className="space-y-6 text-center">
                   <div className="h-12 border-b-2 border-slate-900 dark:border-white w-full opacity-20 italic font-medium flex items-end justify-center pb-1 text-slate-400 underline decoration-dotted">Employee Acknowledgment</div>
                   <p className="text-[10px] font-black uppercase tracking-[0.2em]">Beneficiary Signature</p>
                </div>
              </div>

              <div className="mt-8 text-center">
                 <p className="text-[9px] font-black uppercase tracking-widest text-slate-400 italic">This is an automated financial record of Primelink Management System</p>
                 <p className="text-[9px] font-black uppercase tracking-widest text-slate-400 mt-1">Generated: {new Date().toLocaleString()}</p>
              </div>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
