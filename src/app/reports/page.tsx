'use client';

import DashboardLayout from '@/components/DashboardLayout';
import { 
  BarChart3, 
  TrendingUp, 
  Users, 
  Package, 
  CreditCard,
  ArrowRight,
  FileText,
  PieChart,
  LineChart,
  Download
} from 'lucide-react';
import Link from 'next/link';

export default function ReportsHub() {
  const categories = [
    {
      title: 'General Reports',
      description: 'System-wide analytics, property performance, and overall occupancy trends.',
      icon: BarChart3,
      color: 'blue',
      href: '/reports/general',
      stats: '12 active reports'
    },
    {
      title: 'HR & Payroll',
      description: 'Employee performance, payroll summaries, and leave management analytics.',
      icon: Users,
      color: 'emerald',
      href: '/reports/hr',
      stats: '8 active reports'
    },
    {
      title: 'Inventory & Assets',
      description: 'Stock levels, equipment maintenance, and material usage tracking.',
      icon: Package,
      color: 'orange',
      href: '/reports/inventory',
      stats: '5 active reports'
    },
    {
      title: 'Financial Analytics',
      description: 'Revenue tracking, expense reports, and budget variance analysis.',
      icon: CreditCard,
      color: 'purple',
      href: '/reports/financial',
      stats: '15 active reports'
    }
  ];

  return (
    <DashboardLayout>
      <div className="space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight italic uppercase">ANALYTICS HUB</h1>
          <p className="text-slate-500 mt-1 font-medium">Access comprehensive data insights and generated reports across all departments.</p>
        </div>

        {/* Categories Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {categories.map((cat) => (
            <Link 
              key={cat.title}
              href={cat.href}
              className="glass-card p-8 group hover:translate-y-[-4px] transition-all relative overflow-hidden"
            >
              <div className={`absolute top-0 right-0 p-6 opacity-5 group-hover:opacity-10 transition-all rotate-12`}>
                <cat.icon size={120} />
              </div>

              <div className="flex gap-6 relative z-10">
                <div className={`w-16 h-16 bg-${cat.color}-500/10 text-${cat.color}-500 rounded-2xl flex items-center justify-center`}>
                  <cat.icon size={32} />
                </div>
                <div className="flex-1">
                  <h3 className="text-2xl font-black text-slate-900 dark:text-white mb-2 group-hover:text-accent-gold transition-colors italic uppercase tracking-tighter">
                    {cat.title}
                  </h3>
                  <p className="text-slate-500 text-sm font-medium mb-6 leading-relaxed">
                    {cat.description}
                  </p>
                  <div className="flex justify-between items-center bg-slate-50 dark:bg-slate-900/50 p-4 rounded-xl">
                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">
                      {cat.stats}
                    </span>
                    <span className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-accent-gold group-hover:gap-4 transition-all">
                      Browse Reports <ArrowRight size={14} />
                    </span>
                  </div>
                </div>
              </div>
            </Link>
          ))}
        </div>

        {/* Recent/Pinned Reports Section */}
        <div className="glass-card p-8">
          <div className="flex justify-between items-center mb-8 border-b border-slate-100 dark:border-slate-800 pb-4">
            <h2 className="text-xl font-black text-slate-900 dark:text-white italic uppercase tracking-widest">Recent Downloads</h2>
            <button className="text-xs font-black uppercase tracking-widest text-slate-400 hover:text-accent-gold transition-colors">View All History</button>
          </div>

          <div className="space-y-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="flex items-center justify-between p-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-2xl transition-all border border-transparent hover:border-slate-100 dark:hover:border-slate-800">
                <div className="flex items-center gap-4">
                  <div className="w-10 h-10 bg-slate-100 dark:bg-slate-800 rounded-xl flex items-center justify-center text-slate-400">
                    <FileText size={20} />
                  </div>
                  <div>
                    <h4 className="font-black text-slate-900 dark:text-white uppercase tracking-tight">Monthly_Payroll_March_2024.pdf</h4>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">HR Department • 1.4 MB • Generated 2h ago</p>
                  </div>
                </div>
                <button className="p-3 bg-white dark:bg-slate-900 rounded-xl text-slate-400 hover:text-accent-gold shadow-sm border border-slate-100 dark:border-slate-700 transition-all hover:scale-110">
                  <Download size={18} />
                </button>
              </div>
            ))}
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
