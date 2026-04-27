'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockTokenTransactions, mockTenants } from '@/lib/mock-data';
import { 
  Coins, 
  Zap, 
  Droplets, 
  Search, 
  History, 
  ArrowRight, 
  CheckCircle2, 
  CreditCard,
  Building2,
  Calendar,
  Filter,
  MoreVertical,
  QrCode,
  Download,
  Printer
} from 'lucide-react';

export default function TokensPage() {
  const [utilityType, setUtilityType] = useState<'Electricity' | 'Water'>('Electricity');
  const [amount, setAmount] = useState<string>('');
  const [selectedTenantId, setSelectedTenantId] = useState<string>(mockTenants[0].id);
  const [generatedToken, setGeneratedToken] = useState<{
    code: string;
    units: number;
    amount: number;
    type: string;
  } | null>(null);

  const selectedTenant = mockTenants.find(t => t.id === selectedTenantId) || mockTenants[0];

  const handleGenerateToken = (e: React.FormEvent) => {
    e.preventDefault();
    if (!amount || isNaN(Number(amount))) return;

    // Mock calculation: 1 kWh = 22 KSh, 1 m3 = 78 KSh
    const rate = utilityType === 'Electricity' ? 22 : 78;
    const units = parseFloat((Number(amount) / rate).toFixed(1));
    
    // Random token generation
    const segments = utilityType === 'Electricity' ? 5 : 4;
    const code = Array.from({ length: segments }, () => 
      Math.floor(1000 + Math.random() * 9000).toString()
    ).join(' ');

    setGeneratedToken({
      code,
      units,
      amount: Number(amount),
      type: utilityType
    });
  };

  const statusColors: Record<string, string> = {
    Success: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Pending: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Utility Tokens</h1>
            <p className="text-slate-500 mt-1 font-medium">Generate water and electricity tokens for smart meters.</p>
          </div>
          <div className="flex gap-4">
             <div className="p-3 bg-blue-500/10 rounded-2xl flex items-center gap-3">
               <Zap className="text-blue-500" size={20} />
               <div>
                 <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Electricity Rate</p>
                 <p className="text-sm font-black text-slate-900 dark:text-white">KSh 22 / kWh</p>
               </div>
             </div>
             <div className="p-3 bg-cyan-500/10 rounded-2xl flex items-center gap-3">
               <Droplets className="text-cyan-500" size={20} />
               <div>
                 <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Water Rate</p>
                 <p className="text-sm font-black text-slate-900 dark:text-white">KSh 78 / m³</p>
               </div>
             </div>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Purchase Form */}
          <div className="lg:col-span-2">
            <div className="glass-card p-8">
              <h2 className="text-xl font-black mb-6 flex items-center gap-3">
                <CreditCard className="text-accent-gold" /> Buy Utility Token
              </h2>
              
              <form onSubmit={handleGenerateToken} className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Select Utility</label>
                    <div className="grid grid-cols-2 gap-3 mt-2">
                      <button
                        type="button"
                        onClick={() => setUtilityType('Electricity')}
                        className={`p-4 rounded-xl border-2 flex items-center justify-center gap-3 transition-all ${
                          utilityType === 'Electricity' 
                            ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/20' 
                            : 'border-slate-100 dark:border-slate-800 text-slate-500'
                        }`}
                      >
                        <Zap size={18} /> <span className="font-bold">Electricity</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => setUtilityType('Water')}
                        className={`p-4 rounded-xl border-2 flex items-center justify-center gap-3 transition-all ${
                          utilityType === 'Water' 
                            ? 'border-cyan-500 bg-cyan-50 text-cyan-700 dark:bg-cyan-900/20' 
                            : 'border-slate-100 dark:border-slate-800 text-slate-500'
                        }`}
                      >
                        <Droplets size={18} /> <span className="font-bold">Water</span>
                      </button>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Select Tenant / Meter</label>
                    <select 
                      className="form-input mt-2"
                      value={selectedTenantId}
                      onChange={(e) => setSelectedTenantId(e.target.value)}
                    >
                      {mockTenants.map(t => (
                        <option key={t.id} value={t.id}>{t.name} - {t.unit} ({t.propertyName})</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Purchase Amount</label>
                  <div className="relative mt-2">
                    <span className="absolute left-5 top-1/2 -translate-y-1/2 font-black text-slate-400">KSh</span>
                    <input 
                      type="number" 
                      placeholder="Enter amount (e.g. 1000)" 
                      className="form-input pl-16 py-4 text-xl font-black"
                      value={amount}
                      onChange={(e) => setAmount(e.target.value)}
                      required
                    />
                  </div>
                  <div className="flex gap-2 mt-3">
                    {[500, 1000, 2000, 5000].map(v => (
                      <button 
                        key={v}
                        type="button"
                        onClick={() => setAmount(v.toString())}
                        className="px-4 py-1.5 bg-slate-100 dark:bg-slate-800 rounded-lg text-xs font-bold text-slate-600 hover:bg-accent-gold hover:text-white transition-all"
                      >
                        +{v}
                      </button>
                    ))}
                  </div>
                </div>

                <button 
                  type="submit"
                  className="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center justify-center gap-3"
                >
                  <QrCode size={20} /> Generate {utilityType} Token
                </button>
              </form>
            </div>
          </div>

          {/* Generated Token Result */}
          <div className="lg:col-span-1">
            <div className={`glass-card h-full p-8 border-2 transition-all ${generatedToken ? 'border-accent-gold shadow-2xl animate-in zoom-in-95 duration-300' : 'border-dashed border-slate-200 dark:border-slate-800 opacity-60'}`}>
              {generatedToken ? (
                <div className="flex flex-col h-full">
                  <div className="flex justify-between items-start mb-8">
                    <div className={`w-12 h-12 rounded-2xl flex items-center justify-center ${generatedToken.type === 'Electricity' ? 'bg-blue-500 text-white' : 'bg-cyan-500 text-white'}`}>
                      {generatedToken.type === 'Electricity' ? <Zap size={24} /> : <Droplets size={24} />}
                    </div>
                    <div className="text-right">
                      <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receipt No</p>
                      <p className="font-mono font-bold text-slate-900 dark:text-white">#{Math.random().toString(36).substr(2, 9).toUpperCase()}</p>
                    </div>
                  </div>

                  <div className="bg-slate-50 dark:bg-slate-900 p-6 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 text-center mb-8">
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Your Utility Token</p>
                    <p className="text-2xl font-mono font-black text-slate-900 dark:text-white tracking-widest mb-2">
                       {generatedToken.code}
                    </p>
                    <div className="w-full h-px bg-slate-200 dark:bg-slate-700 my-4"></div>
                    <div className="flex justify-between text-xs font-bold">
                      <span className="text-slate-500">Units Generated</span>
                      <span className="text-slate-900 dark:text-white">{generatedToken.units} {generatedToken.type === 'Electricity' ? 'kWh' : 'm³'}</span>
                    </div>
                  </div>

                  <div className="space-y-3 mt-auto">
                    <button className="w-full py-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-sm font-black flex items-center justify-center gap-2 hover:bg-slate-200 transition-colors">
                      <Printer size={16} /> Print Receipt
                    </button>
                    <button className="w-full py-3 text-sm font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors">
                      Done
                    </button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-col items-center justify-center h-full text-center py-12">
                  <div className="w-20 h-20 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center text-slate-300 mb-6">
                    <History size={40} />
                  </div>
                  <h3 className="text-lg font-black text-slate-400">No Token Generated</h3>
                  <p className="text-sm text-slate-500 max-w-[200px] mt-2 font-medium">Use the form to create a new utility token.</p>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Transaction History */}
        <div className="glass-card overflow-hidden">
          <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-center md:text-left">
            <div>
              <h3 className="text-lg font-black">Transaction History</h3>
              <p className="text-sm text-slate-500 font-medium">Recent utility purchases across the system.</p>
            </div>
            <div className="relative w-full md:w-80">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input 
                type="text" 
                placeholder="Search history..." 
                className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-2.5 text-sm font-medium transition-all outline-none"
              />
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[1000px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-900/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Transaction ID</th>
                  <th className="px-6 py-4">Tenant / Unit</th>
                  <th className="px-6 py-4">Utility Type</th>
                  <th className="px-6 py-4">Amount</th>
                  <th className="px-6 py-4">Units</th>
                  <th className="px-6 py-4">Token Code</th>
                  <th className="px-6 py-4">Date</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {mockTokenTransactions.map((trx) => (
                  <tr key={trx.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group">
                    <td className="px-6 py-4 font-mono text-xs font-bold text-slate-400">{trx.id}</td>
                    <td className="px-6 py-4">
                      <p className="font-bold text-slate-900 dark:text-white">{trx.tenantName}</p>
                      <p className="text-[10px] text-slate-500 font-black uppercase tracking-widest">{trx.unit}</p>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2">
                         {trx.type === 'Electricity' ? (
                           <Zap size={14} className="text-blue-500" />
                         ) : (
                           <Droplets size={14} className="text-cyan-500" />
                         )}
                         <span className="font-bold text-sm text-slate-700 dark:text-slate-300">{trx.type}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4 font-black">KSh {trx.amount.toLocaleString()}</td>
                    <td className="px-6 py-4 font-bold text-slate-500">{trx.units} {trx.type === 'Electricity' ? 'kWh' : 'm³'}</td>
                    <td className="px-2 py-1 font-mono text-xs tracking-tighter text-slate-900 dark:text-white bg-slate-100/50 dark:bg-slate-800/50 rounded">{trx.tokenCode}</td>
                    <td className="px-6 py-4">
                       <p className="text-[10px] font-black text-slate-400">{new Date(trx.date).toLocaleDateString()}</p>
                       <p className="text-[10px] text-slate-400">{new Date(trx.date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
                    </td>
                    <td className="px-6 py-4">
                       <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${statusColors[trx.status]}`}>
                         {trx.status}
                       </span>
                    </td>
                    <td className="px-6 py-4">
                       <button className="p-2 hover:bg-white dark:hover:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm opacity-0 group-hover:opacity-100">
                          <Download size={18} />
                       </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}
