'use client';

import { useParams } from 'next/navigation';
import DashboardLayout from '@/components/DashboardLayout';
import { 
  BarChart3, 
  ChevronLeft, 
  Download, 
  Filter, 
  Calendar,
  FileText,
  PieChart,
  LineChart,
  Table as TableIcon
} from 'lucide-react';
import Link from 'next/link';

export default function CategoryReportPage() {
  const params = useParams();
  const category = params.category as string;

  const categoryTitles: Record<string, string> = {
    general: 'General Systems Report',
    hr: 'Human Resource & Payroll Analysis',
    inventory: 'Inventory & Asset Tracking',
    financial: 'Financial Performance Statements'
  };

  const title = categoryTitles[category] || 'Detailed Report';

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Breadcrumbs & Header */}
        <div className="flex flex-col gap-4">
          <Link 
            href="/reports" 
            className="flex items-center gap-2 text-slate-500 hover:text-accent-gold transition-colors text-xs font-black uppercase tracking-widest w-fit"
          >
            <ChevronLeft size={16} /> Back to Reports Hub
          </Link>
          <div className="flex justify-between items-center">
            <div>
              <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight italic uppercase">{title}</h1>
              <p className="text-slate-500 mt-1 font-medium">Deep dive analytics and downloadable statements for the {category} department.</p>
            </div>
            <button className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2">
              <Download size={18} /> Export PDF
            </button>
          </div>
        </div>

        {/* Filters Bar */}
        <div className="glass-card p-4 flex flex-wrap gap-4 items-center bg-slate-50/50 dark:bg-slate-900/30">
          <div className="flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700 shadow-sm cursor-pointer">
            <Calendar size={16} className="text-accent-gold" />
            <span className="text-xs font-bold">This Month (March 2024)</span>
          </div>
          <div className="flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700 shadow-sm cursor-pointer">
            <Filter size={16} className="text-accent-gold" />
            <span className="text-xs font-bold">All Departments</span>
          </div>
          <button className="ml-auto text-xs font-black uppercase tracking-widest text-slate-400 hover:text-accent-gold transition-colors">Reset Filters</button>
        </div>

        {/* Analytics Placeholders */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="glass-card p-8 h-[400px] flex flex-col items-center justify-center text-center">
            <div className="w-16 h-16 bg-blue-500/10 text-blue-500 rounded-2xl flex items-center justify-center mb-6">
              <LineChart size={32} />
            </div>
            <h3 className="text-xl font-black text-slate-900 dark:text-white uppercase tracking-tight mb-2">Growth Trends</h3>
            <p className="text-slate-500 text-sm max-w-xs mx-auto">Visualizing performance growth over the selected period. Analysis engine is processing live data...</p>
            <div className="mt-8 w-full max-w-xs h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
               <div className="h-full bg-blue-500 w-2/3 animate-pulse"></div>
            </div>
          </div>

          <div className="glass-card p-8 h-[400px] flex flex-col items-center justify-center text-center">
            <div className="w-16 h-16 bg-purple-500/10 text-purple-500 rounded-2xl flex items-center justify-center mb-6">
              <PieChart size={32} />
            </div>
            <h3 className="text-xl font-black text-slate-900 dark:text-white uppercase tracking-tight mb-2">Distribution Analysis</h3>
            <p className="text-slate-500 text-sm max-w-xs mx-auto">Allocating resources and calculating departmental splits. Real-time distribution map loading...</p>
            <div className="mt-8 flex gap-2">
               {[1, 2, 3, 4].map(i => (
                 <div key={i} className={`w-8 h-${i*4} bg-purple-500/40 rounded-t-lg`}></div>
               ))}
            </div>
          </div>
        </div>

        {/* Detailed Data Table Placeholder */}
        <div className="glass-card p-8">
           <div className="flex justify-between items-center mb-8">
              <div className="flex items-center gap-3">
                 <div className="w-10 h-10 bg-accent-gold/10 text-accent-gold rounded-xl flex items-center justify-center">
                    <TableIcon size={20} />
                 </div>
                 <h2 className="text-xl font-black text-slate-900 dark:text-white italic uppercase tracking-widest">Detail Log</h2>
              </div>
              <button className="p-2 border border-slate-100 dark:border-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all">
                 <Download size={18} />
              </button>
           </div>
           
           <div className="space-y-4">
              {[1, 2, 3, 4, 5].map(i => (
                <div key={i} className="grid grid-cols-4 gap-4 p-4 border-b border-slate-50 dark:border-slate-900 opacity-50">
                   <div className="h-4 bg-slate-100 dark:bg-slate-800 rounded w-3/4"></div>
                   <div className="h-4 bg-slate-100 dark:bg-slate-800 rounded w-1/2"></div>
                   <div className="h-4 bg-slate-100 dark:bg-slate-800 rounded w-2/3"></div>
                   <div className="h-4 bg-slate-100 dark:bg-slate-800 rounded w-full"></div>
                </div>
              ))}
           </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
