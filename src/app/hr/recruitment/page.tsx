'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockJobPostings as initialJobs, mockApplications as initialApplications, JobPosting, JobApplication } from '@/lib/mock-data';
import { 
  UserPlus, 
  Search, 
  Plus, 
  MoreVertical,
  Briefcase,
  Mail,
  Calendar,
  X,
  ExternalLink,
  ChevronRight,
  ClipboardList,
  Phone,
  ArrowRight,
  FileText,
  MapPin,
  Clock,
  CheckCircle2,
  XCircle,
  Clock3
} from 'lucide-react';

export default function RecruitmentPage() {
  const [jobs, setJobs] = useState<JobPosting[]>(initialJobs);
  const [applications, setApplications] = useState<JobApplication[]>(initialApplications);
  const [searchTerm, setSearchTerm] = useState('');
  const [activeTab, setActiveTab] = useState<'Postings' | 'Applicants'>('Postings');
  const [showAddJobModal, setShowAddJobModal] = useState(false);
  const [viewingApplicant, setViewingApplicant] = useState<JobApplication | null>(null);

  // Job Form State
  const [jobFormData, setJobFormData] = useState({
    title: '',
    department: 'Operations',
    type: 'Full-time' as JobPosting['type'],
    description: ''
  });

  const handleCreateJob = (e: React.FormEvent) => {
    e.preventDefault();
    const newJob: JobPosting = {
      id: `job-${Date.now().toString().slice(-4)}`,
      title: jobFormData.title,
      department: jobFormData.department,
      type: jobFormData.type,
      status: 'Open',
      postedDate: new Date().toISOString().split('T')[0],
      applicantsCount: 0
    };

    setJobs([newJob, ...jobs]);
    setShowAddJobModal(false);
    setJobFormData({ title: '', department: 'Operations', type: 'Full-time', description: '' });
  };

  const updateApplicantStatus = (id: string, newStatus: JobApplication['status']) => {
    setApplications(applications.map(app => app.id === id ? { ...app, status: newStatus } : app));
    if (viewingApplicant?.id === id) {
      setViewingApplicant(prev => prev ? { ...prev, status: newStatus } : null);
    }
  };

  // Filtering
  const filteredJobs = jobs.filter(job => 
    job.title.toLowerCase().includes(searchTerm.toLowerCase()) || 
    job.department.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const filteredApplications = applications.filter(app => 
    app.candidateName.toLowerCase().includes(searchTerm.toLowerCase()) || 
    app.status.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const statusColors: Record<string, string> = {
    Open: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Closed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    Draft: 'bg-slate-100 text-slate-700 dark:bg-slate-900/30 dark:text-slate-400',
    Applied: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    Screening: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    Interview: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Offered: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    Rejected: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Recruitment</h1>
            <p className="text-slate-500 mt-1 font-medium">Post job openings, track applicants, and manage the hiring pipeline.</p>
          </div>
          <button 
            onClick={() => setShowAddJobModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
          >
            <Plus size={18} /> New Job Opening
          </button>
        </div>

        {/* Tab Selection */}
        <div className="flex gap-1 bg-slate-100 dark:bg-slate-900 p-1 rounded-2xl w-fit">
          <button
            onClick={() => setActiveTab('Postings')}
            className={`px-8 py-2.5 rounded-xl text-sm font-black transition-all ${
              activeTab === 'Postings' 
                ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm' 
                : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
            }`}
          >
            Job Postings
          </button>
          <button
            onClick={() => setActiveTab('Applicants')}
            className={`px-8 py-2.5 rounded-xl text-sm font-black transition-all ${
              activeTab === 'Applicants' 
                ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm' 
                : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
            }`}
          >
            Applicants
          </button>
        </div>

        {/* Filters Box */}
        <div className="glass-card p-4 flex flex-col md:flex-row gap-4 items-center justify-between">
          <div className="relative w-full md:w-96 group">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
            <input 
              type="text" 
              placeholder={`Search ${activeTab.toLowerCase()}...`}
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
        </div>

        {/* Data Display */}
        {activeTab === 'Postings' ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {filteredJobs.length > 0 ? (
              filteredJobs.map(job => (
                <div key={job.id} className="glass-card p-6 group hover:translate-y-[-4px] transition-all relative overflow-hidden">
                  <div className="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-all rotate-12">
                     <Briefcase size={80} />
                  </div>
                  <div className="flex justify-between items-start mb-4 relative z-10">
                    <div className="w-12 h-12 bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center text-slate-600 dark:text-slate-400">
                      <Briefcase size={24} />
                    </div>
                    <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${statusColors[job.status]}`}>
                      {job.status}
                    </span>
                  </div>
                  <h3 className="text-xl font-black text-slate-900 dark:text-white mb-1 group-hover:text-accent-gold transition-colors relative z-10">
                    {job.title}
                  </h3>
                  <p className="text-slate-500 font-bold text-sm uppercase tracking-widest mb-4 relative z-10">
                    {job.department} • {job.type}
                  </p>
                  <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center text-sm relative z-10">
                    <div className="flex flex-col">
                      <span className="text-slate-400 font-bold uppercase text-[10px]">Applicants</span>
                      <span className="font-black text-slate-900 dark:text-white text-lg">{job.applicantsCount}</span>
                    </div>
                    <button className="p-2 bg-slate-50 dark:bg-slate-800 rounded-xl hover:bg-slate-900 hover:text-white dark:hover:bg-slate-50 dark:hover:text-slate-900 transition-all">
                      <ChevronRight size={20} />
                    </button>
                  </div>
                </div>
              ))
            ) : (
              <div className="col-span-full py-20 text-center glass-card">
                <Briefcase size={48} className="mx-auto text-slate-300 mb-4" />
                <p className="font-black text-slate-900 dark:text-white text-xl">No job postings found</p>
              </div>
            )}
          </div>
        ) : (
          <div className="glass-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse min-w-[800px]">
                <thead>
                  <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                    <th className="px-6 py-4">Applicant</th>
                    <th className="px-6 py-4">Job Role</th>
                    <th className="px-6 py-4">Applied Date</th>
                    <th className="px-6 py-4">Current Stage</th>
                    <th className="px-6 py-4"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {filteredApplications.length > 0 ? (
                    filteredApplications.map(app => (
                      <tr key={app.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group">
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center text-slate-400">
                               <UserPlus size={16} />
                            </div>
                            <div className="flex flex-col">
                              <span className="font-bold text-slate-900 dark:text-white">{app.candidateName}</span>
                              <span className="text-xs text-slate-500 italic">{app.email}</span>
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <span className="font-bold text-slate-700 dark:text-slate-300">
                            {jobs.find(j => j.id === app.jobId)?.title || 'Unknown Job'}
                          </span>
                        </td>
                        <td className="px-6 py-4">
                          <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Calendar size={14} />
                            {new Date(app.appliedDate).toLocaleDateString()}
                          </div>
                        </td>
                        <td className="px-6 py-4">
                          <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm ${statusColors[app.status]}`}>
                            {app.status}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-right">
                          <button 
                            onClick={() => setViewingApplicant(app)}
                            className="p-2 bg-white dark:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm opacity-0 group-hover:opacity-100 flex items-center gap-2 ml-auto border border-slate-100 dark:border-slate-700 group/btn"
                          >
                            <span className="text-[10px] font-black uppercase tracking-widest hidden group-hover/btn:block">View Profile</span>
                            <ExternalLink size={16} />
                          </button>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={5} className="py-20 text-center">
                        <UserPlus size={48} className="mx-auto text-slate-300 mb-4" />
                        <p className="font-black text-slate-900 dark:text-white text-xl">No applications found</p>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* New Job Modal */}
      {showAddJobModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="glass-card max-w-2xl w-full p-8 relative my-8">
            <button 
              onClick={() => setShowAddJobModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="flex items-center gap-4 mb-8">
              <div className="w-12 h-12 bg-accent-gold/10 text-accent-gold rounded-xl flex items-center justify-center">
                <ClipboardList size={24} />
              </div>
              <div>
                <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight italic uppercase">POST NEW OPENING</h2>
                <p className="text-slate-500 text-xs font-black uppercase tracking-widest">Recruitment Pipeline</p>
              </div>
            </div>

            <form onSubmit={handleCreateJob} className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2 md:col-span-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Job Title</label>
                  <input 
                    required
                    type="text" 
                    value={jobFormData.title}
                    onChange={(e) => setJobFormData({...jobFormData, title: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                    placeholder="e.g. Senior Software Engineer"
                  />
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Department</label>
                  <select 
                    value={jobFormData.department}
                    onChange={(e) => setJobFormData({...jobFormData, department: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  >
                    <option>Operations</option>
                    <option>Development</option>
                    <option>Finance</option>
                    <option>HR</option>
                    <option>Sales & Marketing</option>
                  </select>
                </div>

                <div className="space-y-2">
                  <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Employment Type</label>
                  <select 
                    value={jobFormData.type}
                    onChange={(e) => setJobFormData({...jobFormData, type: e.target.value as JobPosting['type']})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-bold outline-none transition-all"
                  >
                    <option>Full-time</option>
                    <option>Part-time</option>
                    <option>Contract</option>
                    <option>Internship</option>
                  </select>
                </div>

                <div className="space-y-2 md:col-span-2">
                   <label className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Description</label>
                   <textarea 
                    value={jobFormData.description}
                    onChange={(e) => setJobFormData({...jobFormData, description: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all min-h-[120px]"
                    placeholder="Outline key responsibilities and requirements..."
                   />
                </div>
              </div>

              <div className="pt-6 flex gap-4">
                <button 
                  type="button"
                  onClick={() => setShowAddJobModal(false)}
                  className="flex-1 px-6 py-4 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button 
                  type="submit"
                  className="flex-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 transition-all"
                >
                  Create Posting
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Applicant Profile Modal */}
      {viewingApplicant && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="glass-card max-w-3xl w-full p-0 relative my-8 overflow-hidden">
             {/* Profile Header */}
             <div className="bg-slate-900 dark:bg-slate-800 p-8 text-white relative">
                <button 
                  onClick={() => setViewingApplicant(null)}
                  className="absolute top-6 right-6 p-2 bg-white/10 hover:bg-white/20 text-white rounded-full transition-colors"
                >
                  <X size={20} />
                </button>
                <div className="flex items-center gap-6">
                   <div className="w-24 h-24 bg-accent-gold/20 text-accent-gold rounded-3xl flex items-center justify-center border-2 border-accent-gold/30">
                      <UserPlus size={48} />
                   </div>
                   <div>
                      <h2 className="text-3xl font-black tracking-tight italic uppercase">{viewingApplicant.candidateName}</h2>
                      <p className="text-slate-400 font-bold uppercase tracking-[0.2em] text-xs">Job Applicant • ID: {viewingApplicant.id}</p>
                      <div className="flex gap-4 mt-4">
                         <span className={`px-4 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-lg ${statusColors[viewingApplicant.status]}`}>
                            {viewingApplicant.status}
                         </span>
                         <span className="flex items-center gap-2 text-xs font-bold text-slate-400">
                            <Calendar size={14} /> Applied {new Date(viewingApplicant.appliedDate).toLocaleDateString()}
                         </span>
                      </div>
                   </div>
                </div>
             </div>

             {/* Profile Content */}
             <div className="p-8 space-y-8">
                <div className="grid grid-cols-2 gap-8">
                   <div className="space-y-6">
                      <div className="space-y-2">
                         <h4 className="text-[10px] font-black uppercase tracking-widest text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">Contact Information</h4>
                         <div className="space-y-3">
                            <div className="flex items-center gap-3 text-slate-600 dark:text-slate-300">
                               <Mail size={18} className="text-accent-gold" />
                               <span className="font-bold">{viewingApplicant.email}</span>
                            </div>
                            <div className="flex items-center gap-3 text-slate-600 dark:text-slate-300">
                               <Phone size={18} className="text-accent-gold" />
                               <span className="font-bold">+254 700 000 000</span>
                            </div>
                            <div className="flex items-center gap-3 text-slate-600 dark:text-slate-300">
                               <MapPin size={18} className="text-accent-gold" />
                               <span className="font-bold">Nairobi, Kenya</span>
                            </div>
                         </div>
                      </div>

                      <div className="space-y-2">
                         <h4 className="text-[10px] font-black uppercase tracking-widest text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">Applied Position</h4>
                         <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                            <p className="font-black text-slate-900 dark:text-white uppercase tracking-tight">
                               {jobs.find(j => j.id === viewingApplicant.jobId)?.title || 'Unknown Role'}
                            </p>
                            <p className="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">
                               {jobs.find(j => j.id === viewingApplicant.jobId)?.department || 'General'} Department
                            </p>
                         </div>
                      </div>
                   </div>

                   <div className="space-y-6">
                      <h4 className="text-[10px] font-black uppercase tracking-widest text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">Hiring Actions</h4>
                      <div className="grid grid-cols-1 gap-3">
                         <button 
                            onClick={() => updateApplicantStatus(viewingApplicant.id, 'Screening')}
                            className="flex items-center justify-between p-4 bg-purple-500/5 hover:bg-purple-500 hover:text-white dark:bg-purple-900/10 text-purple-600 dark:text-purple-400 rounded-2xl transition-all border border-purple-500/20 group/btn"
                         >
                            <div className="flex items-center gap-3">
                               <Clock3 size={18} />
                               <span className="font-bold uppercase tracking-widest text-xs">Move to Screening</span>
                            </div>
                            <ArrowRight size={16} className="opacity-0 group-hover/btn:opacity-100 transition-all" />
                         </button>
                         <button 
                            onClick={() => updateApplicantStatus(viewingApplicant.id, 'Interview')}
                            className="flex items-center justify-between p-4 bg-orange-500/5 hover:bg-orange-500 hover:text-white dark:bg-orange-900/10 text-orange-600 dark:text-orange-400 rounded-2xl transition-all border border-orange-500/20 group/btn"
                         >
                            <div className="flex items-center gap-3">
                               <Calendar size={18} />
                               <span className="font-bold uppercase tracking-widest text-xs">Schedule Interview</span>
                            </div>
                            <ArrowRight size={16} className="opacity-0 group-hover/btn:opacity-100 transition-all" />
                         </button>
                         <button 
                            onClick={() => updateApplicantStatus(viewingApplicant.id, 'Offered')}
                            className="flex items-center justify-between p-4 bg-emerald-500/5 hover:bg-emerald-500 hover:text-white dark:bg-emerald-900/10 text-emerald-600 dark:text-emerald-400 rounded-2xl transition-all border border-emerald-500/20 group/btn"
                         >
                            <div className="flex items-center gap-3">
                               <CheckCircle2 size={18} />
                               <span className="font-bold uppercase tracking-widest text-xs">Send Job Offer</span>
                            </div>
                            <ArrowRight size={16} className="opacity-0 group-hover/btn:opacity-100 transition-all" />
                         </button>
                         <button 
                            onClick={() => updateApplicantStatus(viewingApplicant.id, 'Rejected')}
                            className="flex items-center justify-between p-4 bg-red-500/5 hover:bg-red-500 hover:text-white dark:bg-red-900/10 text-red-600 dark:text-red-400 rounded-2xl transition-all border border-red-500/20 group/btn"
                         >
                            <div className="flex items-center gap-3">
                               <XCircle size={18} />
                               <span className="font-bold uppercase tracking-widest text-xs">Reject Applicant</span>
                            </div>
                            <ArrowRight size={16} className="opacity-0 group-hover/btn:opacity-100 transition-all" />
                         </button>
                      </div>
                   </div>
                </div>

                <div className="p-6 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-3xl flex justify-between items-center shadow-2xl">
                   <div className="flex items-center gap-4">
                      <div className="w-10 h-10 bg-white/10 dark:bg-slate-900/10 rounded-xl flex items-center justify-center">
                         <FileText size={20} />
                      </div>
                      <div>
                         <p className="font-black italic uppercase tracking-tighter">Candidate_Resume.pdf</p>
                         <p className="text-[10px] font-bold opacity-60 uppercase tracking-widest">Added 2 days ago • 1.2 MB</p>
                      </div>
                   </div>
                   <button className="px-6 py-2 bg-accent-gold text-white rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg shadow-accent-gold/20 hover:scale-105 transition-all">
                      Download CV
                   </button>
                </div>
             </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
