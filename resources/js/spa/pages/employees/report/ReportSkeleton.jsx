import { Card, PageHeader, Skeleton } from '../../../shared/ui';
import { PeriodSwitch } from './ReportHeader';

/*
| Content-shaped placeholder for the report (rule 6): the same cards in the same
| places, so nothing jumps when the data lands.
*/
function Block({ className = '' }) {
    return (
        <Card className={`p-5 ${className}`}>
            <Skeleton className="h-4 w-40" />
            <Skeleton className="mt-3 h-3 w-64" />
        </Card>
    );
}

export default function ReportSkeleton({ compact, period, onPeriod }) {
    return (
        <div className="flex flex-col gap-5" aria-busy="true" aria-label="Loading report">
            <PageHeader compact={compact} title="Employee report"><PeriodSwitch period={period} onPeriod={onPeriod} /></PageHeader>
            <div className="flex flex-col gap-5">
                <div className={compact ? 'flex flex-col gap-5' : 'grid gap-5 xl:grid-cols-[440px_minmax(0,1fr)]'}>
                    <Block className="h-[560px]" />
                    <Block className="h-[560px]" />
                </div>
                <div className={`grid gap-4 ${compact ? 'grid-cols-2' : 'grid-cols-2 lg:grid-cols-5'}`}>
                    {Array.from({ length: 5 }, (_, i) => <Block key={i} className="h-32" />)}
                </div>
                <Block className="h-[380px]" />
                <Block className="h-[300px]" />
            </div>
        </div>
    );
}
