'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { supabase } from '@/lib/supabase';
import { 
  FileText, 
  Search, 
  Upload, 
  MoreVertical, 
  File, 
  Image as ImageIcon,
  ChevronRight,
  Filter,
  Download,
  Trash2,
  X,
  User,
  Save,
  Clock
} from 'lucide-react';

export default function DocumentsPage() {
  const [documents, setDocuments] = useState<any[]>([]);
  const [tenants, setTenants] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');

  // Upload Form State
  const [newDoc, setNewDoc] = useState({
    tenant_id: '',
    title: '',
    category: 'Lease',
    file_url: 'https://example.com/placeholder.pdf', // Placeholder for now
    file_size: '2.4 MB'
  });

  useEffect(() => {
    fetchData();
  }, []);

  async function fetchData() {
    setLoading(true);
    // Fetch documents with tenant info
    const { data: docs } = await supabase
      .from('documents')
      .select(`
        *,
        tenants (full_name)
      `)
      .order('created_at', { ascending: false });
    
    // Fetch tenants for selection
    const { data: ten } = await supabase
      .from('tenants')
      .select('id, full_name')
      .eq('status', 'Active');

    setDocuments(docs || []);
    setTenants(ten || []);
    setLoading(false);
  }

  async function handleUpload() {
    if (!newDoc.tenant_id || !newDoc.title) return;

    const { error } = await supabase
      .from('documents')
      .insert([newDoc]);

    if (!error) {
      setShowUploadModal(false);
      setNewDoc({
        tenant_id: '',
        title: '',
        category: 'Lease',
        file_url: 'https://example.com/placeholder.pdf',
        file_size: '2.4 MB'
      });
      fetchData();
    }
  }

  async function handleDelete(id: string) {
    const { error } = await supabase
      .from('documents')
      .delete()
      .eq('id', id);
    
    if (!error) {
      fetchData();
    }
  }

  const getFileIcon = (category: string) => {
    switch (category) {
      case 'Lease': return <FileText className="text-red-500" size={24} />;
      case 'ID': return <ImageIcon className="text-blue-500" size={24} />;
      case 'Termination': return <Clock className="text-orange-500" size={24} />;
      default: return <File className="text-slate-400" size={24} />;
    }
  };

  const filteredDocs = documents.filter(doc => 
    doc.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
    doc.tenants?.full_name?.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <DashboardLayout>
      <div className="space-y-8">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Document Repository</h2>
            <p className="text-slate-500 text-sm font-medium">Manage lease agreements, insurance, and maintenance records</p>
          </div>
          <button 
            onClick={() => setShowUploadModal(true)}
            className="flex items-center gap-2 px-6 py-2.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold hover:opacity-90 transition-opacity"
          >
            <Upload size={18} /> Upload Document
          </button>
        </div>

        {/* Filters & Actions */}
        <div className="glass-card p-4 flex flex-col md:flex-row justify-between items-center gap-4">
          <div className="relative w-full md:w-96">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
            <input 
              type="text" 
              placeholder="Search by filename or tenant..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 focus:outline-none focus:ring-2 focus:ring-accent-gold/20 focus:border-accent-gold transition-all text-sm"
            />
          </div>
          <div className="flex items-center gap-2 w-full md:w-auto">
            <button className="flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2.5 border border-slate-200 dark:border-slate-800 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
              <Filter size={14} /> Category
            </button>
          </div>
        </div>

        {/* Documents Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {loading ? (
            <div className="col-span-full flex items-center justify-center p-12">
              <div className="w-8 h-8 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
            </div>
          ) : filteredDocs.map((doc) => (
            <div key={doc.id} className="glass-card p-5 group hover:translate-y-[-4px] transition-all duration-300">
              <div className="flex justify-between items-start mb-6">
                <div className="p-3 bg-slate-50 dark:bg-slate-950 rounded-2xl">
                  {getFileIcon(doc.category)}
                </div>
                <button className="p-1.5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">
                  <MoreVertical size={18} />
                </button>
              </div>
              
              <div className="space-y-1 mb-6">
                <h4 className="font-bold text-slate-900 dark:text-white truncate" title={doc.title}>{doc.title}</h4>
                <p className="text-[10px] font-black text-accent-gold uppercase tracking-widest">{doc.category}</p>
                <p className="text-xs text-slate-500 line-clamp-1">{doc.tenants?.full_name}</p>
              </div>

              <div className="flex justify-between items-center pt-4 border-t border-slate-100 dark:border-slate-800">
                <span className="text-[10px] font-bold text-slate-400">{doc.file_size} • {new Date(doc.created_at).toLocaleDateString()}</span>
                <div className="flex gap-2">
                  <a 
                    href={doc.file_url} 
                    target="_blank" 
                    rel="noopener noreferrer"
                    className="p-2 text-slate-400 hover:text-accent-gold transition-colors"
                  >
                    <Download size={14} />
                  </a>
                  <button 
                    onClick={() => handleDelete(doc.id)}
                    className="p-2 text-slate-400 hover:text-red-500 transition-colors"
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </div>
            </div>
          ))}

          {/* Add Placeholder */}
          <div 
            onClick={() => setShowUploadModal(true)}
            className="border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-3xl p-6 flex flex-col items-center justify-center gap-4 text-center group hover:border-accent-gold transition-colors cursor-pointer"
          >
            <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-full group-hover:bg-accent-gold/10 transition-colors">
              <Upload className="text-slate-400 group-hover:text-accent-gold transition-colors" size={32} />
            </div>
            <div>
              <p className="font-bold text-slate-900 dark:text-white">New Document</p>
              <p className="text-xs text-slate-500">Add to database</p>
            </div>
          </div>
        </div>

        {/* UPLOAD MODAL */}
        {showUploadModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-xl p-10 bg-white dark:bg-slate-900 shadow-2xl relative">
              <button 
                onClick={() => setShowUploadModal(false)}
                className="absolute right-6 top-6 text-slate-400 hover:text-slate-900 dark:hover:text-white"
              >
                <X size={20} />
              </button>
              
              <div className="space-y-6">
                <div>
                  <h3 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight italic">UPLOAD DOCUMENT</h3>
                  <p className="text-sm text-slate-500">Associate a new document with our database.</p>
                </div>

                <div className="space-y-4">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Select Tenant</label>
                    <div className="relative">
                      <User className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 shadow-sm" size={18} />
                      <select 
                        value={newDoc.tenant_id}
                        onChange={(e) => setNewDoc({...newDoc, tenant_id: e.target.value})}
                        className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold"
                      >
                        <option value="">Choose a tenant...</option>
                        {tenants.map(t => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                      </select>
                    </div>
                  </div>

                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Document Title</label>
                    <input 
                      type="text" 
                      placeholder="e.g., Signed Lease July 2024"
                      value={newDoc.title}
                      onChange={(e) => setNewDoc({...newDoc, title: e.target.value})}
                      className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Category</label>
                      <select 
                        value={newDoc.category}
                        onChange={(e) => setNewDoc({...newDoc, category: e.target.value})}
                        className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold"
                      >
                        <option value="Lease">Lease Agreement</option>
                        <option value="ID">National ID</option>
                        <option value="Termination">Termination Letter</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                    <div className="space-y-2">
                      <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">File URL (Mock)</label>
                      <input 
                        type="text" 
                        value={newDoc.file_url}
                        readOnly
                        className="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold text-slate-400 cursor-not-allowed"
                      />
                    </div>
                  </div>
                </div>

                <div className="flex justify-end gap-4 pt-6 border-t border-slate-100 dark:border-slate-800">
                  <button onClick={() => setShowUploadModal(false)} className="px-6 py-3 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-slate-900 transition-colors">Cancel</button>
                  <button 
                    onClick={handleUpload}
                    className="px-10 py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl flex items-center gap-2"
                  >
                    Save to Database <Save size={18} />
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
