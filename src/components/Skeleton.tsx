export default function Skeleton({ className }: { className?: string }) {
  return (
    <div 
      className={`animate-pulse bg-slate-200 dark:bg-slate-800 rounded-lg ${className}`}
    ></div>
  );
}

export function CardSkeleton() {
  return (
    <div className="glass-card p-6 space-y-4">
      <div className="flex justify-between items-center">
        <Skeleton className="w-10 h-10 rounded-xl" />
        <Skeleton className="w-16 h-6 rounded-full" />
      </div>
      <div className="space-y-2">
        <Skeleton className="w-24 h-4" />
        <Skeleton className="w-2/3 h-8" />
      </div>
    </div>
  );
}

export function TableRowSkeleton() {
  return (
    <div className="flex items-center gap-4 py-4 px-6">
      <Skeleton className="w-10 h-10 rounded-full shrink-0" />
      <div className="flex-1 space-y-2">
        <Skeleton className="w-1/3 h-4" />
        <Skeleton className="w-1/4 h-3" />
      </div>
      <Skeleton className="w-20 h-4 md:block hidden" />
      <Skeleton className="w-24 h-6 rounded-lg md:block hidden" />
      <Skeleton className="w-8 h-8 rounded-lg" />
    </div>
  );
}
