'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockLeaveRequests as initialLeaves, mockEmployees, LeaveRequest } from '@/lib/mock-data';
import { 
  CalendarClock, 
  Search, 
  Plus, 
  Filter, 
  MoreVertical,
  CheckCircle,
  Clock,
  Calendar,
  X,
  AlertTriangle,
  User,
  Check,
  Ban,
  MessageSquare,
  ArrowRight
} from 'lucide-react';

export default function LeavesPage() {
  const [leaves, setLeaves] = useState<LeaveRequest[]>(initialLeaves);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState<string>('All');
  const [showApplyModal, setShowApplyModal] = useState(false);

  // Form State
  const [formData, setFormData] = useState({
    employeeId: '',
    type: 'Annual' as LeaveRequest['type'],
    startDate: '',
    endDate: '',
    reason: ''
  });

  // Calculate Days
  const calculateDays = (start: string, end: string) => {
    if (!start || !end) return 0;
    const s = new Date(start);
    const e = new Date(end);
    const diff = e.getTime() - s.getTime();
    const days = Math.ceil(diff / (1000 * 3600 * 24)) + 1;
    return days > 0 ? days : 0;
  };

  const currentDuration = calculateDays(formData.startDate, formData.endDate);

  const handleApplyLeave = (e: React.FormEvent) => {
    e.preventDefault();
    const employee = mockEmployees.find(emp => emp.id === formData.employeeId);
    
    if (!employee) return;

    const newRequest: LeaveRequest = {
      id: `lv-${Date.now()}`,
      employeeId: employee.id,
      employeeName: employee.name,
      type: formData.type,
      startDate: formData.startDate,
      endDate: formData.endDate,
      days: currentDuration,
      status: 'Pending',
      reason: formData.reason
    };

    setLeaves([newRequest, ...leaves]);
    setShowApplyModal(false);
    setFormData({ employeeId: '', type: 'Annual', startDate: '', endDate: '', reason: '' });
  };

  const updateLeaveStatus = (id: string, newStatus: 'Approved' | 'Rejected') => {
    setLeaves(leaves.map(l => l.id === id ? { ...l, status: newStatus } : l));
  };

  // Filtering
  const filteredLeaves = leaves.filter(lv => {
    const matchesSearch = lv.employeeName.toLowerCase().includes(searchTerm.toLowerCase()) || 
                          lv.type.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'All' || lv.status === filterStatus;
    
    return matchesSearch && matchesStatus;
  });

  const statusColors: Record<string, string> = {
    Approved: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Pending: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Rejected: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  const typeStyles: Record<string, string> = {
    Annual: 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20',
    Sick: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20',
    Maternity: 'text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20',
    Paternity: 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/20',
    Personal: 'text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/20',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Leaves Management</h1>
            <p className="text-slate-500 mt-1 font-medium">Track staff absences, medical time-off, and holiday schedules.</p>
          </div>
          <button 
            onClick={() => setShowApplyModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
          >
            <Plus size={18} /> Apply for Leave
          </button>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-orange-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-orange-500/10 rounded-2xl flex items-center justify-center text-orange-500">
                <Clock size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Pending Requests</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {leaves.filter(l => l.status === 'Pending').length}
                </h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-blue-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                <Calendar size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Approved This Month</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {leaves.filter(l => l.status === 'Approved').length}
                </h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-red-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-red-500/10 rounded-2xl flex items-center justify-center text-red-500">
                <AlertTriangle size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Rejected Requests</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {leaves.filter(l => l.status === 'Rejected').length}
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
              placeholder="Search by employee or type..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
          <div className="flex gap-4 w-full md:w-auto overflow-x-auto custom-scrollbar pb-2 md:pb-0">
            {['All', 'Pending', 'Approved', 'Rejected'].map(status => (
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

        {/* Leaves Data Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[800px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Employee</th>
                  <th className="px-6 py-4">Type & Reason</th>
                  <th className="px-6 py-4">Duration</th>
                  <th className="px-6 py-4">Days</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredLeaves.length > 0 ? (
                  filteredLeaves.map((lv) => (
                    <tr 
                      key={lv.id} 
                      className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group"
                    >
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center text-slate-400">
                            <User size={16} />
                          </div>
                          <div>
                            <p className="font-bold text-slate-900 dark:text-white">{lv.employeeName}</p>
                            <p className="text-[10px] text-slate-500 font-mono tracking-tighter uppercase">{lv.employeeId}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1">
                          <span className={`px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest w-fit block ${typeStyles[lv.type] || 'bg-slate-100 dark:bg-slate-800'}`}>
                            {lv.type}
                          </span>
                          <p className="text-xs text-slate-500 italic max-w-[200px] truncate">"{lv.reason}"</p>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-sm font-medium text-slate-600 dark:text-slate-400">
                        <div className="flex items-center gap-2">
                          <span className="font-bold text-slate-900 dark:text-white">
                            {new Date(lv.startDate).toLocaleDateString()}
                          </span>
                          <ArrowRight size={12} className="text-slate-300" />
                          <span className="font-bold text-slate-900 dark:text-white">
                            {new Date(lv.endDate).toLocaleDateString()}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4 font-black text-slate-900 dark:text-white">
                        {lv.days} {lv.days === 1 ? 'Day' : 'Days'}
                      </td>
                      <td className="px-6 py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${statusColors[lv.status]}`}>
                          {lv.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                          {lv.status === 'Pending' && (
                            <>
                              <button 
                                onClick={() => updateLeaveStatus(lv.id, 'Approved')}
                                className="p-2 bg-green-500/10 text-green-600 hover:bg-green-500 hover:text-white rounded-xl transition-all shadow-sm border border-green-500/20"
                                title="Approve Request"
                              >
                                <Check size={18} />
                              </button>
                              <button 
                                onClick={() => updateLeaveStatus(lv.id, 'Rejected')}
                                className="p-2 bg-red-500/10 text-red-600 hover:bg-red-500 hover:text-white rounded-xl transition-all shadow-sm border border-red-500/20"
                                title="Reject Request"
                              >
                                <Ban size={18} />
                              </button>
                            </>
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
                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500 bg-slate-50/50 dark:bg-slate-900/10">
                      <CalendarClock size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                      <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No leave requests</p>
                      <p className="text-slate-500">There are no leave applications matching your current filters.</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Apply Modal */}
      {showApplyModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="glass-card max-w-2xl w-full p-8 relative my-8">
            <button 
              onClick={() => setShowApplyModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="flex items-center gap-4 mb-8">
              <div className="w-12 h-12 bg-accent-gold/10 text-accent-gold rounded-xl flex items-center justify-center">
                <CalendarClock size={24} />
              </div>
              <div>
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight italic">SUBMIT LEAVE REQUEST</h2>
                <p className="text-slate-500 text-xs font-black uppercase tracking-widest">HR Internal Dashboard</p>
              </div>
            </div>

            <form onSubmit={handleApplyLeave} className="space-y-6">
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
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Leave Type</label>
                  <select 
                    value={formData.type}
                    onChange={(e) => setFormData({...formData, type: e.target.value as any})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  >
                    <option>Annual</option>
                    <option>Sick</option>
                    <option>Maternity</option>
                    <option>Paternity</option>
                    <option>Personal</option>
                  </select>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Duration (calculated)</label>
                  <div className="w-full bg-slate-100 dark:bg-slate-800 rounded-xl px-4 py-3 text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <Clock size={16} className="text-accent-gold" />
                    {currentDuration} {currentDuration === 1 ? 'Day' : 'Days'}
                  </div>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Start Date</label>
                  <input 
                    required
                    type="date" 
                    value={formData.startDate}
                    onChange={(e) => setFormData({...formData, startDate: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  />
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">End Date</label>
                  <input 
                    required
                    type="date" 
                    value={formData.endDate}
                    onChange={(e) => setFormData({...formData, endDate: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  />
                </div>

                <div className="space-y-2 md:col-span-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Reason for Leave</label>
                  <div className="relative">
                    <MessageSquare className="absolute left-4 top-4 text-slate-400" size={18} />
                    <textarea 
                      required
                      value={formData.reason}
                      onChange={(e) => setFormData({...formData, reason: e.target.value})}
                      className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium outline-none transition-all min-h-[100px]"
                      placeholder="Brief comment on the reason..."
                    />
                  </div>
                </div>
              </div>

              <div className="pt-6 flex gap-4">
                <button 
                  type="button"
                  onClick={() => setShowApplyModal(false)}
                  className="flex-1 px-6 py-4 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button 
                  type="submit"
                  className="flex-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 transition-all"
                >
                  Submit for Approval
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
