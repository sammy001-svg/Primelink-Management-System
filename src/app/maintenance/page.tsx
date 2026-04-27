'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { supabase } from '@/lib/supabase';
import { 
  Wrench, 
  Clock, 
  CheckCircle2, 
  AlertCircle, 
  Search, 
  Plus, 
  ExternalLink,
  Star,
  Phone,
  X,
  ChevronRight,
  User,
  Save
} from 'lucide-react';

export default function MaintenancePage() {
  const [requests, setRequests] = useState<any[]>([]);
  const [employees, setEmployees] = useState<any[]>([]);
  const [properties, setProperties] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNewModal, setShowNewModal] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<any>(null);
  const [showResponseModal, setShowResponseModal] = useState(false);
  
  // Response State
  const [responseNotes, setResponseNotes] = useState('');
  const [assignedStaff, setAssignedStaff] = useState('');
  const [status, setStatus] = useState('');

  useEffect(() => {
    fetchData();
  }, []);

  async function fetchData() {
    setLoading(true);
    // Fetch requests with tenant and unit info
    const { data: reqs } = await supabase
      .from('maintenance_requests')
      .select(`
        *,
        tenants (full_name),
        units (unit_number, properties (title))
      `)
      .order('created_at', { ascending: false });
    
    // Fetch active employees
    const { data: emps } = await supabase
      .from('employees')
      .select('*')
      .eq('status', 'Active');

    setRequests(reqs || []);
    setEmployees(emps || []);

    // Fetch properties
    const { data: props } = await supabase.from('properties').select('id, title');
    setProperties(props || []);

    setLoading(false);
  }

  async function handleUpdateStatus() {
    if (!selectedRequest) return;
    
    const { error } = await supabase
      .from('maintenance_requests')
      .update({
        status: status || selectedRequest.status,
        assigned_staff_id: assignedStaff || selectedRequest.assigned_staff_id,
        admin_notes: responseNotes,
        updated_at: new Date().toISOString()
      })
      .eq('id', selectedRequest.id);

    if (!error) {
      setShowResponseModal(false);
      fetchData();
    }
  }

  const priorityColors = {
    Low: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-400',
    Medium: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    High: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Urgent: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  const statusIcons = {
    Pending: Clock,
    'In Progress': Wrench,
    Completed: CheckCircle2,
  };

  const statusColors = {
    Pending: 'text-orange-500',
    'In Progress': 'text-blue-500',
    Completed: 'text-green-500',
  };

  return (
    <DashboardLayout>
      <div className="space-y-8 relative">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Maintenance Overview</h2>
          <button 
            onClick={() => setShowNewModal(true)}
            className="flex items-center justify-center gap-2 px-6 py-2.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold hover:opacity-90 transition-opacity"
          >
            <Plus size={18} />
            New Request
          </button>
        </div>

        {/* ... (rest of the dashboard content) */}
        
        {/* NEW REQUEST MODAL */}
        {showNewModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-xl p-8 bg-white dark:bg-slate-900 shadow-2xl relative">
              <button 
                onClick={() => setShowNewModal(false)}
                className="absolute right-6 top-6 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors"
              >
                <X size={20} />
              </button>
              
              <div className="space-y-6">
                <div>
                  <h3 className="text-2xl font-bold text-slate-900 dark:text-white">New Service Request</h3>
                  <p className="text-sm text-slate-500">Provide details about the maintenance issue</p>
                </div>

                <div className="grid grid-cols-2 gap-6">
                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Property</label>
                    <select className="px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold w-full">
                      {properties.map(p => <option key={p.id}>{p.title}</option>)}
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Priority</label>
                    <select className="form-select text-sm font-bold">
                      <option>Low</option>
                      <option>Medium</option>
                      <option>High</option>
                      <option>Emergency</option>
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Issue Subject</label>
                  <input type="text" placeholder="e.g., Broken pipe, HVAC failure" className="form-input" />
                </div>

                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Description</label>
                  <textarea rows={3} placeholder="Describe the problem in detail..." className="form-textarea"></textarea>
                </div>

                <div className="flex justify-end gap-4 pt-6">
                  <button onClick={() => setShowNewModal(false)} className="px-6 py-3 text-sm font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 transition-colors">Cancel</button>
                  <button className="px-10 py-3.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2">
                    Submit Ticket <ChevronRight size={18} />
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <div className="lg:col-span-2 space-y-6">
            <h3 className="text-xl font-bold flex items-center gap-2">
              <Wrench size={20} className="text-accent-gold" />
              Active Requests
            </h3>
            
            <div className="space-y-4">
              {loading ? (
                <div className="flex items-center justify-center p-12">
                  <div className="w-8 h-8 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
                </div>
              ) : requests.map((request) => {
                const StatusIcon = statusIcons[request.status as keyof typeof statusIcons] || AlertCircle;
                return (
                  <div key={request.id} className="glass-card p-6 hover:translate-y-[-2px] transition-transform group">
                    <div className="flex justify-between items-start mb-4">
                      <div>
                        <div className="flex items-center gap-2 mb-1">
                          <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${priorityColors[request.priority as keyof typeof priorityColors]}`}>
                            {request.priority}
                          </span>
                          <span className="text-xs text-slate-500 font-medium tracking-tighter">#{request.id.slice(0, 8)}</span>
                        </div>
                        <h4 className="text-lg font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{request.title}</h4>
                        <p className="text-sm text-slate-500">{request.units?.properties?.title} - Unit {request.units?.unit_number}</p>
                      </div>
                      <div className={`flex items-center gap-1.5 font-bold text-sm ${statusColors[request.status as keyof typeof statusColors]}`}>
                        <StatusIcon size={16} />
                        {request.status}
                      </div>
                    </div>
                    
                    <p className="text-sm text-slate-600 dark:text-slate-400 mb-6 line-clamp-2">
                      {request.description}
                    </p>
                    
                    <div className="flex justify-between items-center pt-4 border-t border-slate-100 dark:border-slate-800">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-full bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 flex items-center justify-center text-xs font-bold">
                          {request.tenants?.full_name?.split(' ').map((n: string) => n[0]).join('')}
                        </div>
                        <div className="text-xs">
                          <p className="font-bold text-slate-900 dark:text-white">{request.tenants?.full_name}</p>
                          <p className="text-slate-400 font-medium">Submitted {new Date(request.created_at).toLocaleDateString()}</p>
                        </div>
                      </div>
                      <button 
                        onClick={() => {
                          setSelectedRequest(request);
                          setResponseNotes(request.admin_notes || '');
                          setAssignedStaff(request.assigned_staff_id || '');
                          setStatus(request.status);
                          setShowResponseModal(true);
                        }}
                        className="text-xs text-accent-gold font-black uppercase tracking-[0.15em] flex items-center gap-2 hover:opacity-80 transition-opacity"
                      >
                        Respond & Assign <ChevronRight size={14} />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="space-y-6">
            <h3 className="text-xl font-bold flex items-center gap-2">
               <User size={20} className="text-accent-gold" />
               Staff Availability
            </h3>
            
            <div className="space-y-4">
              {employees.map((emp) => (
                <div key={emp.id} className="glass-card p-5 space-y-4">
                  <div className="flex justify-between items-start">
                    <div>
                      <p className="text-[10px] font-black text-accent-gold uppercase tracking-[0.2em] mb-1">{emp.role}</p>
                      <h4 className="font-bold text-slate-900 dark:text-white leading-tight">{emp.full_name}</h4>
                    </div>
                    <div className="flex items-center gap-1 text-[10px] font-black text-green-500 uppercase tracking-widest bg-green-500/10 px-2 py-0.5 rounded-lg">
                      {emp.status}
                    </div>
                  </div>
                  
                  <div className="flex items-center gap-2 text-xs text-slate-500 font-medium">
                    <Phone size={14} className="text-slate-400" />
                    {emp.phone || 'No phone provided'}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* RESPONSE MODAL */}
        {showResponseModal && selectedRequest && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-xl p-10 bg-white dark:bg-slate-900 shadow-2xl relative overflow-hidden">
              <button 
                onClick={() => setShowResponseModal(false)}
                className="absolute right-6 top-6 text-slate-400 hover:text-slate-900 dark:hover:text-white"
              >
                <X size={20} />
              </button>
              
              <div className="space-y-6">
                <div>
                  <h3 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Manage Request</h3>
                  <p className="text-sm text-slate-500">Update status and assign staff for #{selectedRequest.id.slice(0, 8)}</p>
                </div>

                <div className="p-4 bg-slate-50 dark:bg-slate-950 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Issue</p>
                  <p className="text-sm font-bold text-slate-900 dark:text-white">{selectedRequest.title}</p>
                  <p className="text-xs text-slate-500 mt-2">{selectedRequest.description}</p>
                </div>

                <div className="grid grid-cols-2 gap-6">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Update Status</label>
                    <select 
                      value={status}
                      onChange={(e) => setStatus(e.target.value)}
                      className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold"
                    >
                      <option value="Pending">Pending</option>
                      <option value="In Progress">In Progress</option>
                      <option value="Completed">Completed</option>
                    </select>
                  </div>
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Assign Staff</label>
                    <select 
                      value={assignedStaff}
                      onChange={(e) => setAssignedStaff(e.target.value)}
                      className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold"
                    >
                      <option value="">Unassigned</option>
                      {employees.map(emp => (
                        <option key={emp.id} value={emp.id}>{emp.full_name} ({emp.role})</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Response to Tenant</label>
                  <textarea 
                    rows={4} 
                    value={responseNotes}
                    onChange={(e) => setResponseNotes(e.target.value)}
                    placeholder="e.g., Plumber will arrive tomorrow at 10 AM..."
                    className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold resize-none"
                  ></textarea>
                </div>

                <div className="flex justify-end gap-4 pt-6 border-t border-slate-100 dark:border-slate-800">
                  <button onClick={() => setShowResponseModal(false)} className="px-6 py-3 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-slate-900 transition-colors">Cancel</button>
                  <button 
                    onClick={handleUpdateStatus}
                    className="px-10 py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl flex items-center gap-2"
                  >
                    Save Changes <Save size={18} />
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
