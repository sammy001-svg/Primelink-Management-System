'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { supabase } from '@/lib/supabase';
import { 
  FileText, 
  Search, 
  File, 
  Download,
  AlertCircle,
  Clock,
  ShieldCheck
} from 'lucide-react';

export default function TenantDocumentsPage() {
  const [documents, setDocuments] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    fetchDocuments();
  }, []);

  async function fetchDocuments() {
    setLoading(true);
    const { data: { session } } = await supabase.auth.getSession();
    const user = session?.user;
    if (!user) return;

    // Get tenant ID
    const { data: tenant } = await supabase
      .from('tenants')
      .select('id')
      .eq('user_id', user.id)
      .single();

    if (tenant) {
      const { data } = await supabase
        .from('documents')
        .select('*')
        .eq('tenant_id', tenant.id)
        .order('created_at', { ascending: false });
      
      setDocuments(data || []);
    }
    setLoading(false);
  }

  const getFileIcon = (category: string) => {
    switch (category) {
      case 'Lease': return <FileText className="text-accent-gold" size={28} />;
      case 'ID': return <ShieldCheck className="text-blue-500" size={28} />;
      case 'Termination': return <Clock className="text-orange-500" size={28} />;
      default: return <File className="text-slate-400" size={28} />;
    }
  };

  const filteredDocs = documents.filter(doc => 
    doc.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
    doc.category.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <DashboardLayout>
      <div className="space-y-8">
        <div className="flex justify-between items-center">
          <div>
            <h2 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight uppercase">My Documents</h2>
            <p className="text-slate-500 text-sm font-bold tracking-tight">Official records and signed agreements</p>
          </div>
          <div className="hidden md:flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
            <ShieldCheck size={16} className="text-green-500" />
            <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Verified Secure Storage</span>
          </div>
        </div>

        {/* Search */}
        <div className="relative max-w-md">
          <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
          <input 
            type="text" 
            placeholder="Search documents..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-12 pr-6 py-4 rounded-4xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 focus:outline-none focus:ring-4 focus:ring-accent-gold/10 focus:border-accent-gold transition-all text-sm font-bold"
          />
        </div>

        {loading ? (
          <div className="flex items-center justify-center p-20">
            <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
          </div>
        ) : filteredDocs.length > 0 ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {filteredDocs.map((doc) => (
              <div key={doc.id} className="glass-card p-6 group hover:translate-y-[-4px] transition-all duration-300">
                <div className="flex justify-between items-start mb-8">
                  <div className="w-14 h-14 bg-slate-50 dark:bg-slate-950 rounded-2xl flex items-center justify-center border border-slate-100 dark:border-slate-800">
                    {getFileIcon(doc.category)}
                  </div>
                  <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${
                    doc.category === 'Lease' ? 'bg-accent-gold/10 text-accent-gold' :
                    doc.category === 'ID' ? 'bg-blue-500/10 text-blue-500' :
                    'bg-orange-500/10 text-orange-500'
                  }`}>
                    {doc.category}
                  </span>
                </div>
                
                <h4 className="font-black text-slate-900 dark:text-white text-lg mb-2 truncate" title={doc.title}>
                  {doc.title}
                </h4>
                
                <div className="flex items-center gap-3 text-xs text-slate-400 font-bold mb-8">
                   <span>{doc.file_size || '---'}</span>
                   <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                   <span>Uploaded {new Date(doc.created_at).toLocaleDateString()}</span>
                </div>

                <a 
                  href={doc.file_url} 
                  target="_blank"
                  rel="noopener noreferrer"
                  className="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2 hover:opacity-90 active:scale-[0.98] transition-all shadow-xl"
                >
                  <Download size={16} /> Download Copy
                </a>
              </div>
            ))}
          </div>
        ) : (
          <div className="glass-card p-20 flex flex-col items-center justify-center text-center gap-4">
            <div className="w-20 h-20 bg-slate-50 dark:bg-slate-950 rounded-full flex items-center justify-center border border-slate-100 dark:border-slate-800">
              <File size={40} className="text-slate-300" />
            </div>
            <div>
              <h3 className="text-xl font-bold text-slate-900 dark:text-white">No documents found</h3>
              <p className="text-sm text-slate-500 max-w-sm mt-2">Any official documents uploaded by the management will appear here for your review and download.</p>
            </div>
          </div>
        )}

        <div className="p-6 bg-blue-50 dark:bg-blue-900/10 rounded-4xl border border-blue-100 dark:border-blue-900/30 flex items-start gap-4">
          <AlertCircle size={20} className="text-blue-500 shrink-0 mt-1" />
          <div className="space-y-1">
            <p className="text-sm font-black text-blue-900 dark:text-blue-200 uppercase tracking-widest">Document Security Policy</p>
            <p className="text-xs text-blue-700/70 dark:text-blue-300/60 font-bold leading-relaxed">
              These are official property records. Tenants have view-only access and cannot modify or delete these files. If you notice any discrepancy, please contact the management directly.
            </p>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
