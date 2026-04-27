'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { supabase } from '@/lib/supabase';
import { 
  Wrench, 
  Clock, 
  CheckCircle2, 
  AlertCircle, 
  Plus, 
  ChevronRight,
  X,
  User,
  MessageSquare,
  AlertTriangle
} from 'lucide-react';

interface MaintenanceRequest {
  id: string;
  title: string;
  description: string;
  priority: string;
  status: string;
  admin_notes: string | null;
  created_at: string;
  assigned_staff_id: string | null;
  employees: {
    full_name: string;
    role: string;
  } | null;
}

export default function TenantMaintenancePage() {
  const [requests, setRequests] = useState<MaintenanceRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNewModal, setShowNewModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Form State
  const [newRequest, setNewRequest] = useState({
    title: '',
    description: '',
    priority: 'Medium'
  });

  useEffect(() => {
    fetchRequests();
  }, []);

  async function fetchRequests() {
    setLoading(true);
    const { data: { session } } = await supabase.auth.getSession();
    const user = session?.user;
    if (!user) {
      setLoading(false);
      return;
    }

    const { data: tenantData, error: tError } = await supabase
      .from('tenants')
      .select('id')
      .eq('user_id', user.id)
      .maybeSingle();

    if (tError) {
      console.error('Error fetching tenant record:', tError);
    }

    if (tenantData) {
      const { data, error: rError } = await supabase
        .from('maintenance_requests')
        .select(`
          *,
          employees (
            full_name,
            role
          )
        `)
        .eq('tenant_id', tenantData.id)
        .order('created_at', { ascending: false });

      if (rError) {
        console.error('Error fetching maintenance requests:', rError);
      }
      setRequests(data as MaintenanceRequest[] || []);
    } else {
      console.warn('No tenant record found for user:', user.id);
    }
    setLoading(false);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    
    const { data: { session } } = await supabase.auth.getSession();
    const user = session?.user;
    if (!user) return;

    const { data: tenantData, error: tenantError } = await supabase
      .from('tenants')
      .select('id')
      .eq('user_id', user.id)
      .maybeSingle();

    if (tenantError) {
      console.error('Error fetching tenant:', tenantError);
      alert(`Tenant check failed: ${tenantError.message}`);
      setSubmitting(false);
      return;
    }

    if (!tenantData) {
      console.error('No tenant profile found for user:', user.id);
      alert('Error: Tenant profile not found. Please contact management.');
      setSubmitting(false);
      return;
    }

    // Fetch unit and property info via join
    const { data: leaseData, error: leaseError } = await supabase
      .from('leases')
      .select('unit_id, units(property_id)')
      .eq('tenant_id', tenantData.id)
      .eq('status', 'Active')
      .maybeSingle();

    if (leaseError) {
      console.error('Error fetching lease data:', leaseError);
      alert(`Lease check failed: ${leaseError.message}`);
      setSubmitting(false);
      return;
    }

    const unitId = leaseData?.unit_id;
    const propertyId = (leaseData?.units as any)?.property_id;

    const { error: insertError } = await supabase
      .from('maintenance_requests')
      .insert({
        tenant_id: tenantData.id,
        unit_id: unitId || null,
        property_id: propertyId || null,
        title: newRequest.title,
        description: newRequest.description,
        priority: newRequest.priority,
        status: 'Pending'
      });

    if (insertError) {
      console.error('Error submitting maintenance request:', insertError);
      alert(`Submission failed: ${insertError.message}`);
    } else {
      setShowNewModal(false);
      setNewRequest({ title: '', description: '', priority: 'Medium' });
      fetchRequests();
    }
    setSubmitting(false);
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
      <div className="space-y-8 animate-in fade-in duration-500">
        <div className="flex justify-between items-center">
          <div>
            <h2 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Maintenance</h2>
            <p className="text-slate-500 font-medium">Request service and track progress</p>
          </div>
          <button 
            onClick={() => setShowNewModal(true)}
            className="flex items-center gap-2 px-6 py-3 bg-accent-gold text-slate-900 rounded-2xl font-black text-xs uppercase tracking-widest hover:translate-y-[-2px] transition-all shadow-xl"
          >
            <Plus size={18} /> New Request
          </button>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-6">
            {requests.length > 0 ? (
              requests.map((request) => {
                const StatusIcon = statusIcons[request.status as keyof typeof statusIcons] || AlertCircle;
                return (
                  <div key={request.id} className="glass-card p-8 group hover:border-accent-gold/30 transition-all">
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                      <div className="flex-1 space-y-4">
                        <div className="flex items-center gap-3">
                          <span className={`px-2 py-1 rounded text-[10px] font-black uppercase tracking-widest ${priorityColors[request.priority as keyof typeof priorityColors]}`}>
                            {request.priority}
                          </span>
                          <div className={`flex items-center gap-1.5 font-black text-[10px] uppercase tracking-widest ${statusColors[request.status as keyof typeof statusColors]}`}>
                            <StatusIcon size={14} />
                            {request.status}
                          </div>
                          <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            Submitted {new Date(request.created_at).toLocaleDateString()}
                          </span>
                        </div>
                        
                        <h3 className="text-xl font-black text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">
                          {request.title}
                        </h3>
                        <p className="text-sm text-slate-500 font-medium leading-relaxed max-w-2xl">
                          {request.description}
                        </p>

                        {request.admin_notes && (
                          <div className="p-4 bg-slate-50 dark:bg-slate-900/50 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-2">
                            <p className="flex items-center gap-2 text-[10px] font-black text-accent-gold uppercase tracking-widest">
                              <MessageSquare size={12} /> Management Response
                            </p>
                            <p className="text-sm text-slate-600 dark:text-slate-400 font-medium italic">
                              "{request.admin_notes}"
                            </p>
                          </div>
                        )}
                      </div>

                      <div className="lg:w-64 space-y-4 pt-6 lg:pt-0 lg:border-l border-slate-100 dark:border-slate-800 lg:pl-8">
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned Staff</p>
                        {request.employees ? (
                          <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-full bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 flex items-center justify-center font-black text-xs">
                              {request.employees.full_name.split(' ').map(n => n[0]).join('')}
                            </div>
                            <div>
                               <p className="text-sm font-black text-slate-900 dark:text-white">{request.employees.full_name}</p>
                               <p className="text-[10px] font-black text-accent-gold uppercase tracking-widest">{request.employees.role}</p>
                            </div>
                          </div>
                        ) : (
                          <div className="flex items-center gap-2 text-slate-400 italic text-xs font-medium">
                            <Clock size={14} /> Waiting for assignment...
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })
            ) : (
              <div className="text-center py-20 glass-card">
                <AlertTriangle size={48} className="mx-auto text-slate-300 mb-4" />
                <h3 className="text-xl font-black text-slate-900 dark:text-white">No requests yet</h3>
                <p className="text-slate-500 font-medium">Any issues with your unit? Click "New Request" to get started.</p>
              </div>
            )}
          </div>
        )}

        {/* NEW REQUEST MODAL */}
        {showNewModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-xl p-10 bg-white dark:bg-slate-900 shadow-2xl relative overflow-hidden group">
              <div className="absolute top-0 left-0 w-full h-2 bg-accent-gold"></div>
              
              <button 
                onClick={() => setShowNewModal(false)}
                className="absolute right-6 top-6 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors"
              >
                <X size={20} />
              </button>
              
              <form onSubmit={handleSubmit} className="space-y-6">
                <div>
                  <h3 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Report an Issue</h3>
                  <p className="text-sm text-slate-500 font-medium">We'll assign someone to look into it as soon as possible.</p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                  <div className="space-y-2 text-left">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Issue Subject</label>
                    <input 
                      type="text" 
                      placeholder="e.g., Broken Sink" 
                      required
                      value={newRequest.title}
                      onChange={(e) => setNewRequest({...newRequest, title: e.target.value})}
                      className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 appearance-none"
                    />
                  </div>
                  <div className="space-y-2 text-left">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Priority Level</label>
                    <select 
                      value={newRequest.priority}
                      onChange={(e) => setNewRequest({...newRequest, priority: e.target.value})}
                      className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 appearance-none cursor-pointer"
                    >
                      <option>Low</option>
                      <option>Medium</option>
                      <option>High</option>
                      <option>Urgent</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-2 text-left">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</label>
                  <textarea 
                    rows={4} 
                    placeholder="Provide more details and specify availability..." 
                    required
                    value={newRequest.description}
                    onChange={(e) => setNewRequest({...newRequest, description: e.target.value})}
                    className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 appearance-none resize-none"
                  ></textarea>
                </div>

                <div className="flex justify-end gap-4 pt-6 border-t border-slate-100 dark:border-slate-800">
                  <button type="button" onClick={() => setShowNewModal(false)} className="px-6 py-3 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-slate-900 transition-colors">Cancel</button>
                  <button 
                    disabled={submitting}
                    className="px-10 py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2 disabled:opacity-50"
                  >
                    {submitting ? 'Submitting...' : <>Submit Request <ChevronRight size={18} /></>}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
