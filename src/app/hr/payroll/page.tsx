'use client';

import { useState, useRef } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockPayroll as initialPayroll, mockEmployees, Payroll } from '@/lib/mock-data';
import { 
  Wallet, 
  Search, 
  Download, 
  Filter, 
  MoreVertical,
  CheckCircle,
  Clock,
  Calendar,
  DollarSign,
  TrendingDown,
  TrendingUp,
  X,
  Printer,
  FileText,
  User,
  Hash,
  Briefcase
} from 'lucide-react';

export default function PayrollPage() {
  const [employees] = useState(mockEmployees);
  const [payrollRecords, setPayrollRecords] = useState<Payroll[]>(initialPayroll);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterMonth, setFilterMonth] = useState<string>('All');
  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [selectedMonth, setSelectedMonth] = useState('March');
  const [viewingPayslip, setViewingPayslip] = useState<Payroll | null>(null);

  // Print Ref
  const printRef = useRef<HTMLDivElement>(null);

  // Processing Logic
  const handleRunPayroll = () => {
    const newRecords: Payroll[] = employees.map(emp => {
      const basic = emp.salary;
      const allowances = basic * 0.1; // 10% allowance mock
      const nssf = 1080; // Kenya standard
      const nhif = 1700; // Kenya standard mock
      const paye = (basic + allowances) * 0.15; // 15% tax mock
      const deductions = nssf + nhif + paye;
      const net = (basic + allowances) - deductions;

      return {
        id: `pyr-${selectedMonth.toLowerCase()}-${emp.employeeNumber.toLowerCase()}`,
        employeeId: emp.id,
        employeeName: emp.name,
        month: selectedMonth,
        year: 2024,
        basicSalary: basic,
        allowances: allowances,
        deductions: deductions,
        netPay: net,
        status: 'Paid',
        paymentDate: new Date().toISOString().split('T')[0]
      };
    });

    setPayrollRecords([...payrollRecords, ...newRecords]);
    setShowGenerateModal(false);
  };

  const handlePrint = () => {
    window.print();
  };

  // Filtering
  const filteredPayroll = payrollRecords.filter(pr => {
    const matchesSearch = pr.employeeName.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesMonth = filterMonth === 'All' || pr.month === filterMonth;
    
    return matchesSearch && matchesMonth;
  });

  const totalNetPay = filteredPayroll.reduce((sum, pr) => sum + pr.netPay, 0);
  const totalDeductions = filteredPayroll.reduce((sum, pr) => sum + pr.deductions, 0);

  const statusColors: Record<string, string> = {
    Paid: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Pending: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6 print:hidden">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Payroll Management</h1>
            <p className="text-slate-500 mt-1 font-medium">Process salaries, manage deductions, and generate payslips.</p>
          </div>
          <button 
            onClick={() => setShowGenerateModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
          >
            <DollarSign size={18} /> Run Payroll
          </button>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-blue-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                <TrendingUp size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Total Net Pay</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {totalNetPay.toLocaleString()}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-red-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-red-500/10 rounded-2xl flex items-center justify-center text-red-500">
                <TrendingDown size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Total Deductions</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">KSh {totalDeductions.toLocaleString()}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-green-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-green-500/10 rounded-2xl flex items-center justify-center text-green-500">
                <CheckCircle size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Staff Paid</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {filteredPayroll.filter(pr => pr.status === 'Paid').length} / {filteredPayroll.length}
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
              placeholder="Search by employee name..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
          <div className="flex gap-4 w-full md:w-auto overflow-x-auto custom-scrollbar pb-2 md:pb-0">
            {['All', 'January', 'February', 'March', 'April'].map(month => (
              <button
                key={month}
                onClick={() => setFilterMonth(month)}
                className={`px-6 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-all ${
                  filterMonth === month 
                    ? 'bg-slate-900 text-white shadow-lg dark:bg-slate-50 dark:text-slate-900' 
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700'
                }`}
              >
                {month}
              </button>
            ))}
          </div>
        </div>

        {/* Payroll Data Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[800px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Employee</th>
                  <th className="px-6 py-4">Period</th>
                  <th className="px-6 py-4">Basic Salary</th>
                  <th className="px-6 py-4">Allowances</th>
                  <th className="px-6 py-4">Deductions</th>
                  <th className="px-6 py-4 text-accent-gold">Net Pay</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredPayroll.length > 0 ? (
                  filteredPayroll.map((pr) => (
                    <tr 
                      key={pr.id} 
                      className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group"
                    >
                      <td className="px-6 py-4">
                        <div>
                          <p className="font-bold text-slate-900 dark:text-white">{pr.employeeName}</p>
                          <p className="text-xs text-slate-500 font-mono tracking-wider italic">ID: {pr.employeeId}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400 font-medium">
                          <Calendar size={14} className="text-slate-400" />
                          {pr.month} {pr.year}
                        </div>
                      </td>
                      <td className="px-6 py-4 font-semibold text-slate-700 dark:text-slate-300">
                        KSh {pr.basicSalary.toLocaleString()}
                      </td>
                      <td className="px-6 py-4 text-green-600 dark:text-green-400 font-medium text-sm">
                        + KSh {pr.allowances.toLocaleString()}
                      </td>
                      <td className="px-6 py-4 text-red-600 dark:text-red-400 font-medium text-sm">
                        - KSh {pr.deductions.toLocaleString()}
                      </td>
                      <td className="px-6 py-4 font-black text-slate-900 dark:text-white text-lg">
                        KSh {pr.netPay.toLocaleString()}
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${statusColors[pr.status]}`}>
                          {pr.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                          <button 
                            onClick={() => setViewingPayslip(pr)}
                            className="p-2 bg-white dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm border border-slate-100 dark:border-slate-700 flex items-center gap-2 pr-4 group/btn"
                          >
                            <FileText size={18} />
                            <span className="text-[10px] font-black uppercase tracking-widest hidden group-hover/btn:block">View Payslip</span>
                          </button>
                          <button className="p-2 bg-white dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm border border-slate-100 dark:border-slate-700">
                            <Download size={18} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center text-slate-500 bg-slate-50/50 dark:bg-slate-900/10">
                      <Wallet size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                      <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No payroll records</p>
                      <p className="text-slate-500">We couldn't find any payroll data for the selected period.</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Generate Payroll Modal */}
      {showGenerateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
          <div className="glass-card max-w-2xl w-full p-8 relative flex flex-col items-center text-center">
            <button 
              onClick={() => setShowGenerateModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="w-20 h-20 bg-accent-gold/10 text-accent-gold rounded-full flex items-center justify-center mb-6">
              <DollarSign size={40} />
            </div>
            <h2 className="text-2xl font-black text-slate-900 dark:text-white mb-3">Process Monthly Payroll</h2>
            <p className="text-slate-500 mb-6 max-w-md font-medium">
              Select a month to generate payroll records for all <span className="text-slate-900 dark:text-white font-black">{employees.length}</span> active employees.
            </p>
            
            <div className="grid grid-cols-3 gap-3 mb-8 w-full max-w-md">
              {['January', 'February', 'March', 'April', 'May', 'June'].map(m => (
                <button
                  key={m}
                  onClick={() => setSelectedMonth(m)}
                  className={`p-3 rounded-xl text-sm font-black transition-all border-2 ${
                    selectedMonth === m 
                      ? 'bg-slate-900 border-slate-900 text-white dark:bg-slate-50 dark:border-slate-50 dark:text-slate-900 shadow-lg' 
                      : 'bg-slate-50 border-transparent text-slate-500 hover:border-slate-200 dark:bg-slate-800 dark:text-slate-400'
                  }`}
                >
                  {m}
                </button>
              ))}
            </div>

            <div className="flex gap-4 w-full justify-center">
              <button 
                onClick={() => setShowGenerateModal(false)}
                className="px-6 py-3 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
              >
                Cancel
              </button>
              <button 
                onClick={handleRunPayroll}
                className="px-10 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-sm uppercase tracking-widest shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 transition-all"
              >
                Generate & Confirm
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Payslip View Modal */}
      {viewingPayslip && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="max-w-3xl w-full relative print:m-0 print:p-0">
             {/* Modal Controls (Hidden on Print) */}
             <div className="absolute -top-14 right-0 flex gap-3 print:hidden">
              <button 
                onClick={handlePrint}
                className="p-3 bg-white dark:bg-slate-900 text-slate-900 dark:text-white rounded-xl shadow-xl border border-slate-100 dark:border-slate-800 hover:scale-105 transition-all flex items-center gap-2 font-black text-xs uppercase tracking-widest"
              >
                <Printer size={18} /> Print Payslip
              </button>
              <button 
                onClick={() => setViewingPayslip(null)}
                className="p-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl shadow-xl hover:scale-105 transition-all"
              >
                <X size={18} />
              </button>
            </div>

            {/* Payslip Content */}
            <div className="bg-white dark:bg-slate-950 rounded-3xl p-10 shadow-3xl text-slate-900 dark:text-white min-h-[800px] border-2 border-slate-50 dark:border-slate-900 print:shadow-none print:border-none print:p-8">
              {/* Header */}
              <div className="flex justify-between items-start border-b-4 border-slate-900 dark:border-white pb-8 mb-10">
                <div>
                  <h2 className="text-3xl font-black tracking-tighter uppercase italic">PrimeLink</h2>
                  <p className="text-sm font-bold text-slate-500 uppercase tracking-widest">Management System</p>
                  <div className="mt-4 space-y-1 text-xs font-bold text-slate-500 uppercase tracking-wider">
                    <p>Riverside Drive, Nairobi</p>
                    <p>P.O. Box 7762 - 00100</p>
                    <p>contact@primelink.com</p>
                  </div>
                </div>
                <div className="text-right">
                  <span className="px-4 py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-[10px] font-black uppercase tracking-[0.2em] rounded-lg">
                    Official payslip
                  </span>
                  <h3 className="text-xl font-black mt-6 uppercase tracking-tight">{viewingPayslip.month} {viewingPayslip.year}</h3>
                  <p className="text-xs font-bold text-slate-400 mt-1 uppercase tracking-widest">Payslip ID: {viewingPayslip.id.toUpperCase()}</p>
                </div>
              </div>

              {/* Employee Area */}
              <div className="grid grid-cols-2 gap-10 mb-12">
                <div className="space-y-4">
                  <h4 className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 border-b border-slate-100 dark:border-slate-900 pb-2">Employee Details</h4>
                  <div className="space-y-3">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-slate-50 dark:bg-slate-900 rounded-xl flex items-center justify-center text-slate-400">
                        <User size={20} />
                      </div>
                      <div>
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Name</p>
                        <p className="font-black text-lg leading-tight uppercase tracking-tight">{viewingPayslip.employeeName}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-slate-50 dark:bg-slate-900 rounded-xl flex items-center justify-center text-slate-400">
                        <Hash size={20} />
                      </div>
                      <div>
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Employee Number</p>
                        <p className="font-black text-lg leading-tight uppercase tracking-tight">
                          {mockEmployees.find(e => e.id === viewingPayslip.employeeId)?.employeeNumber || 'PL-EMP-N/A'}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="space-y-4">
                  <h4 className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 border-b border-slate-100 dark:border-slate-900 pb-2">Employment Data</h4>
                  <div className="space-y-3">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 bg-slate-50 dark:bg-slate-900 rounded-xl flex items-center justify-center text-slate-400">
                        <Briefcase size={20} />
                      </div>
                      <div>
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Designation</p>
                        <p className="font-black text-lg leading-tight uppercase tracking-tight">
                          {mockEmployees.find(e => e.id === viewingPayslip.employeeId)?.role || 'N/A'}
                        </p>
                      </div>
                    </div>
                    <div>
                      <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Pay Status</p>
                      <p className="font-black text-green-600 dark:text-green-400 italic">CLEARED & PAID</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Earnings & Deductions Tables */}
              <div className="grid grid-cols-2 gap-x-12 mb-16">
                <div className="space-y-4">
                  <h4 className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Earnings</h4>
                  <div className="border-t-2 border-slate-900 dark:border-white">
                    <div className="flex justify-between py-4 border-b border-slate-100 dark:border-slate-900">
                      <span className="font-bold text-sm uppercase tracking-widest">Basic Salary</span>
                      <span className="font-black text-sm">KSh {viewingPayslip.basicSalary.toLocaleString()}</span>
                    </div>
                    <div className="flex justify-between py-4 border-b border-slate-100 dark:border-slate-900">
                      <span className="font-bold text-sm uppercase tracking-widest">Hse Allowance</span>
                      <span className="font-black text-sm">KSh {viewingPayslip.allowances.toLocaleString()}</span>
                    </div>
                    <div className="flex justify-between py-6 pt-10">
                      <span className="font-black text-base uppercase tracking-tighter italic">Gross Pay</span>
                      <span className="font-black text-lg underline decoration-4 underline-offset-8">
                        KSh {(viewingPayslip.basicSalary + viewingPayslip.allowances).toLocaleString()}
                      </span>
                    </div>
                  </div>
                </div>
                <div className="space-y-4">
                  <h4 className="text-[10px] font-black uppercase tracking-[0.2em] text-red-500">Deductions</h4>
                  <div className="border-t-2 border-slate-900 dark:border-white">
                    <div className="flex justify-between py-4 border-b border-slate-100 dark:border-slate-900">
                      <span className="font-bold text-sm uppercase tracking-widest">PAYE (Tax)</span>
                      <span className="font-black text-sm">KSh {(viewingPayslip.deductions * 0.7).toLocaleString()}</span>
                    </div>
                    <div className="flex justify-between py-4 border-b border-slate-100 dark:border-slate-900">
                      <span className="font-bold text-sm uppercase tracking-widest">NHIF Contribution</span>
                      <span className="font-black text-sm">KSh 1,700</span>
                    </div>
                    <div className="flex justify-between py-4 border-b border-slate-100 dark:border-slate-900">
                      <span className="font-bold text-sm uppercase tracking-widest">NSSF Contribution</span>
                      <span className="font-black text-sm">KSh 1,080</span>
                    </div>
                    <div className="flex justify-between py-6 pt-10">
                      <span className="font-black text-base uppercase tracking-tighter italic text-red-500">Total Deductions</span>
                      <span className="font-black text-lg text-red-500 underline decoration-4 underline-offset-8">
                        KSh {viewingPayslip.deductions.toLocaleString()}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Net Pay Area */}
              <div className="bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl p-8 flex justify-between items-center mb-12 shadow-2xl">
                <div>
                  <p className="text-[10px] font-black uppercase tracking-[0.3em] opacity-60">Net Amount Disbursed</p>
                  <h5 className="text-4xl font-black mt-1 italic tracking-tighter uppercase underline decoration-accent-gold decoration-4 underline-offset-8">Kenya Shillings</h5>
                </div>
                <div className="text-right">
                  <span className="text-4xl font-black tracking-tighter">KSh {viewingPayslip.netPay.toLocaleString()}</span>
                </div>
              </div>

              {/* Footer */}
              <div className="pt-10 border-t border-slate-100 dark:border-slate-900 flex justify-between items-end">
                <div className="space-y-4">
                  <div className="w-48 h-12 border-b-2 border-slate-900 dark:border-white opacity-20"></div>
                  <p className="text-[10px] font-black uppercase tracking-widest">Authorized Signature</p>
                </div>
                <div className="text-right space-y-1">
                  <p className="text-[10px] font-black uppercase tracking-widest text-slate-400 italic">This is a computer generated document</p>
                  <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Printed: {new Date().toLocaleString()}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
