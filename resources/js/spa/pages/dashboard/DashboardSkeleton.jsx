import { Card, Skeleton } from '../../shared/ui';

// Content-shaped first paint: the hero banner, the KPI grid, and the two chart
// cards all hold their real proportions, so nothing shifts when data lands.
export default function DashboardSkeleton({ deviceClass }) {
    const isDesktop = deviceClass === 'desktop';

    return (
        <div className={isDesktop ? 'space-y-5' : 'space-y-4'} aria-busy="true" aria-label="Loading dashboard">
            <Card className="p-5">
                <Skeleton className="h-4 w-36" />
                <Skeleton className="mt-2 h-3 w-52" />
                <div className={`mt-4 grid gap-3 ${isDesktop ? 'grid-cols-5' : 'grid-cols-2'}`}>
                    {Array.from({ length: isDesktop ? 5 : 4 }, (_, i) => (
                        <div key={i} className="rounded-xl border border-slate-100 p-4">
                            <Skeleton className="h-6 w-12" />
                            <Skeleton className="mt-2 h-3 w-16" />
                        </div>
                    ))}
                </div>
            </Card>

            <div className={`grid gap-${isDesktop ? '5' : '3'} ${isDesktop ? 'grid-cols-3' : 'grid-cols-2'}`}>
                <Card className={`p-5 ${isDesktop ? 'col-span-1' : 'col-span-2'}`}>
                    <Skeleton className="h-4 w-32" />
                    <div className="mt-5 flex justify-center">
                        <Skeleton className="h-[168px] w-[168px] rounded-full" />
                    </div>
                </Card>
                <div className={`grid grid-cols-2 gap-${isDesktop ? '5' : '3'} ${isDesktop ? 'col-span-2' : 'col-span-2'}`}>
                    {Array.from({ length: 4 }, (_, i) => (
                        <Card key={i} className="p-5">
                            <Skeleton className="h-7 w-14" />
                            <Skeleton className="mt-2 h-3 w-20" />
                            <Skeleton className="mt-4 h-2.5 w-28" />
                        </Card>
                    ))}
                </div>
            </div>

            <div className={`grid gap-5 ${isDesktop ? 'grid-cols-3' : 'grid-cols-1'}`}>
                <Card className={`p-5 ${isDesktop ? 'col-span-2' : ''}`}>
                    <Skeleton className="h-4 w-40" />
                    <div className="mt-6 flex items-end gap-3">
                        {Array.from({ length: 7 }, (_, i) => (
                            <div key={i} className="flex flex-1 flex-col items-center gap-2">
                                <Skeleton className="w-2.5 rounded-full" style={{ height: `${40 + ((i * 37) % 90)}px` }} />
                                <Skeleton className="h-2 w-6" />
                            </div>
                        ))}
                    </div>
                </Card>
                <Card className="p-5">
                    <Skeleton className="h-4 w-28" />
                    <div className="mt-4 space-y-3">
                        {Array.from({ length: 5 }, (_, i) => (
                            <div key={i} className="flex items-center gap-3">
                                <Skeleton className="h-3 flex-[1.5]" />
                                <Skeleton className="h-3 flex-1" />
                                <Skeleton className="h-3 w-10" />
                            </div>
                        ))}
                    </div>
                </Card>
            </div>
        </div>
    );
}
