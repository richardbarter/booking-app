# Background: the dynamic booking problem

This document explains the scheduling problem at the centre of this app:
- why it's hard
- how other industries deal with the same problem
- what research and maths already exist, and where they fall short

The design that follows from it is in [planning/00-overview.md](planning/00-overview.md), section 5.

## 1. The problem

A clinic offers appointments of different lengths, for example a 90-minute initial assessment, a 60-minute treatment and a 30-minute review. Two practitioners work 09:00–17:00. Customers book online and choose their own times, days or weeks in advance.

Before anyone books, nobody knows how a day will fill up: how many appointments of each length there'll be, or when. So start times are offered on a grid, say every 15 minutes, and every booking changes what else can fit:

- A practitioner is free 09:00–12:00. Someone books a 30-minute review at 10:00.
- A 60-minute treatment can no longer start at 09:15, because it would run until 10:15.
- The free time is now 09:00–10:00 and 10:30–12:00. Only start times that fit entirely inside one of those gaps can be offered.

Working out what fits is simple. The hard question is **which of the times that fit should be offered**.

### Wasted time

If every time that fits is offered, customers can pick times that leave time unusable. A 30-minute review booked at 09:15 leaves 09:00–09:15 empty, and no service is short enough to fill it. Over a week, scattered bookings can leave many small holes. Fifteen minutes here and there, across every practitioner and every day, adds up to lost income.

### Lost bookings

Hide 09:15 and the problem flips. The customer who wanted 09:15 might take 09:00 instead, or might book somewhere else. If nobody books 09:00 either, hiding 09:15 lost a booking and saved nothing.

### The core trade-off

> A booking now is certain. The better use you're protecting that time for is only a possibility.

Which mistake costs more depends on three things:
- how busy the business is
- how far away the appointment is
- how customers react when their preferred time isn't shown

None of these is known for certain at the moment the decision has to be made.

## 2. It's several problems, not one

| Problem | Example | Known as |
|---|---|---|
| Dead gaps | 15 minutes nobody can book | Orphan nights (hotels, holiday lets); external fragmentation (computer memory) |
| Long appointments squeezed out | Scattered 30-minute bookings leave no room for a 90 | Protection levels and capacity control (airline revenue management) |
| Which practitioner gets the booking | Put a 30-minute booking in A's small gap and keep B's long free morning | Best fit (bin packing, memory allocators) |
| Which times to show | Hiding 09:15 might move someone to 09:00, or lose them | Assortment optimisation (choice-based revenue management) |
| Deciding without knowing the future | Accept or hide each time as requests arrive | Online algorithms; dynamic programming |
| When to relax the rules | Time protected for a long appointment is unlikely to fill the day before | Booking curves; controls that change as the date approaches |
| No-shows and cancellations | A customer doesn't turn up | Overbooking (airlines); outpatient scheduling research |

This app tackles the first six. No-shows are out of scope for now.

## 3. How other fields deal with it

### Airlines: revenue management

A seat on a flight is perishable: once the plane leaves, an empty seat earns nothing. Cheap fares also tend to be booked before expensive ones. So airlines face the same question this app does: sell a cheap seat now, or hold it for someone who might pay more later?

In 1972 Ken Littlewood of BOAC published a simple rule for this (section 4.1). After US airline deregulation in 1978, the area grew into what's now called **revenue management**, which includes protection levels, booking limits, EMSR and bid prices (all covered in section 4). It's now standard practice for airlines, hotels, car hire and many other businesses. The standard textbook is Talluri and van Ryzin's *The Theory and Practice of Revenue Management* (2004).

### Hotels and holiday lets: orphan nights

Rooms and holiday lets sold by the night have the same gap problem. A one- or two-night gap between stays, too short for the usual minimum stay, is called an **orphan night** or gap night. Common fixes are:
- minimum-stay rules, so that bookings can't leave short gaps
- relaxing those rules for particular gaps, or as the date gets close
- discounting gap nights so they're more likely to sell

Holiday-let pricing tools automate much of this. PriceLabs, for example, discounts gaps of two nights or less by default. Relaxing restrictions as the date approaches is the idea behind this app's relaxation window.

### Clinics: appointment scheduling research

Healthcare appointment scheduling has been studied since the 1950s:
- **Bailey (1952) and Welch & Bailey (1952)** studied outpatient clinics. They proposed what's now called the Bailey–Welch rule: book a couple of patients at the start of a session, then one per interval, to balance patient waiting time against clinician idle time.
- **Cayirli & Veral (2003) and Gupta & Denton (2008)** review the decades of work since.

Most of this research is about a different trade-off from ours: appointment lengths that vary unpredictably, patients who don't turn up, and waiting rooms. Usually the clinic chooses the time, not the patient.

The closest work treats booking as a series of decisions made without knowing the future. Patrick, Puterman & Queyranne (2008) model scheduling patients of different priorities as a Markov decision process. They find it too large to solve exactly, so they use an approximation instead (section 4.4).

### Computer memory: fragmentation

Programs ask for blocks of memory of different sizes, then free them later, and a memory allocator decides where each block goes. If it chooses badly, free memory ends up split into holes too small to use. This is called **external fragmentation**, and it's the same thing as dead gaps in a diary.

Allocator design has been studied since the 1960s; Wilson et al. (1995) surveyed over 150 papers. Policies such as **best fit**, which puts each block in the smallest hole it fits, tend to keep fragmentation low in practice.

There's one key difference. An allocator chooses where every block goes, but customers choose their own times. A booking system can only steer where bookings go, by choosing which times to offer and which practitioner to assign.

### Booking software today

Several salon and wellness booking tools have a "minimise gaps" setting:
- **Fresha** has "reduce gaps" and "eliminate gaps" options that favour slots next to existing bookings, shift times and blocked time. Fresha says it raised occupancy by up to 44%, though that's the company's own figure.
- **Timely, Ovatu and MassageBook** have similar settings that favour times next to existing bookings and breaks.
- **Bookly**, a WordPress plugin, can show only the nearest available slot.
- **Calendly** doesn't appear to have one. Users have asked for it on its community forum.

These are on/off switches or a couple of fixed levels. None that we found lets a business choose how strict to be on a sliding scale, or adjusts to the business's own busy and quiet periods.

## 4. The maths that exists

### 4.1 Littlewood's rule

Littlewood's rule answers one question: how many seats should be held back for passengers paying the higher fare? It says:

> Accept a low-fare booking as long as
> `low fare ≥ high fare × P(high-fare demand is more than the seats still held back)`

Put another way, keep holding seats back while the chance of selling them at the high fare is greater than `low fare ÷ high fare`.

**Applied to bookings.** Treat the "fare" as the value of a booking (minutes booked, or revenue) and a "seat" as a block of time. Suppose a 30-minute booking would split a 90-minute free block so that no 90-minute appointment could fit any more. A simplified version of the rule says: hide that slot only if the chance that a 90-minute booking would otherwise use the block is more than 30 ÷ 90, about 1 in 3.

**Where it falls short:**
- It assumes two products, with the cheap ones booked first. Real bookings come in any order and in several lengths.
- Seats are interchangeable, but minutes aren't. Whether a block of time is useful depends on what's either side of it.
- It ignores what happens to the rest of the block. The leftover pieces might still be filled by shorter appointments.
- It needs the probability of demand, which a small business may not have enough data to estimate well.

### 4.2 EMSR: more than two products

Belobaba (1987, 1989) extended Littlewood's idea to many fare classes with the **expected marginal seat revenue** (EMSR) methods, known as EMSR-a and EMSR-b. They're shortcuts rather than exact answers: they aren't guaranteed optimal with more than two fare classes, but they work well in practice and are widely used.

In this app, the "fare classes" would be the service lengths (30, 60 and 90 minutes). The problem with joined-up time from section 4.1 still applies.

### 4.3 Bid prices: what each unit of time is worth

Instead of holding back a fixed number of seats, you give every unit of capacity a **bid price**: what it's expected to earn if it stays free. A request is accepted only if its value is at least the total bid price of the units it would use. Airlines use this across whole networks of flights, and hotels use it across multi-night stays.

In this app, every 15-minute unit of a practitioner's time could have a bid price based on how likely it is to be booked: high on busy Tuesday mornings, low on quiet Friday afternoons.

**Where it falls short:**
- **It's built for large scale.** Talluri & van Ryzin (1998) showed bid prices aren't optimal in general, though they come close when there's a lot of capacity and demand. A small clinic is the opposite: a few practitioners and a few dozen slots a day.
- **It ignores neighbouring time.** Bid prices add up unit by unit, but wasted time depends on how units sit next to each other. A lone 15 minutes is worth less than the same 15 minutes attached to a longer gap.

### 4.4 Dynamic programming: the perfect answer, and why it's out of reach

In principle, the best possible policy can be found with a **Markov decision process**. You describe:
- every possible state of the diary
- the chance of each kind of request arriving
- the value of each decision

Then you work out the best decision for every state.

The catch is the number of states. It's every possible arrangement of bookings, for every practitioner, on every day in the booking window, and it grows far too quickly to solve exactly. This is known as the "curse of dimensionality". Research such as Patrick et al. (2008) uses **approximate dynamic programming** to find good but not perfect policies, then tests them by simulation.

### 4.5 Even knowing the future, it's hard

Suppose you knew every booking request in advance. Would picking the best schedule then be easy?

- **Fixed times, one practitioner: yes.** Picking the most valuable set of non-overlapping appointments at fixed times is a classic problem called *weighted interval scheduling*. Dynamic programming solves it efficiently.
- **Flexible times: no.** If each request could take one of several times, picking the most bookings is the *job interval selection problem*. It's NP-hard even for a single practitioner (Spieksma 1999; Chuzhoy, Ostrovsky & Rabani 2006). That means nobody knows a fast method that always finds the exact best answer. Fast methods do exist that are guaranteed to get within a fixed fraction of it.

### 4.6 Not knowing the future: online algorithms

In computer science, an **online algorithm** has to make each decision before it sees the next request. It's judged by its *competitive ratio*: in the worst case, how far it can end up from the best result you could get with hindsight.

Lipton & Tomkins (1994) studied accepting or refusing time intervals as they arrive, where longer intervals are worth more. They showed that the best possible guarantee gets worse as the difference between the longest and shortest requests grows. It grows with the logarithm of the ratio between them.

Their setup isn't the same as booking ahead, but the lesson still applies: no rule does well in every situation. It's better to use what you know about likely demand than to plan for the worst case.

### 4.7 Customer choice: which times to show

A booking system doesn't just accept or refuse. It chooses which set of times to show, and the customer picks from that set. Revenue management calls this **choice-based** control or **assortment optimisation**: deciding which set of products to offer, based on a model of how customers choose between them (Talluri & van Ryzin 2004).

The hard part is that model. If 09:15 is hidden, how many of the people who wanted it take 09:00, and how many leave? Airlines call this the **recapture rate**. For a small business, nobody knows it until it's measured.

## 5. What's still unsolved

Each piece of maths above helps, but none fits this situation completely:

- **Time is continuous.** Most revenue management treats capacity as interchangeable units, like seats or rooms. In a diary, the value of each minute depends on the minutes around it.
- **Capacity is tiny.** Methods that work well for big airlines and hotels, like bid prices, rely on large numbers. A sole practitioner has a few dozen slots a day.
- **There isn't much data.** Small businesses have short, noisy booking histories, so any estimate of demand is rough.
- **Customers choose for themselves.** Nobody knows how customers react when their preferred time is hidden, and it varies from business to business.
- **Several practitioners and flexible times multiply the possibilities**, even before uncertainty is added.
- **Not everything is about revenue.** Practitioners' preferences, breaks and customer satisfaction matter too.

That's why real systems, this app included, combine sensible rules, data and simulation rather than relying on a single formula.

## 6. How this app approaches it

| Idea | Where it comes from | How the app uses it |
|---|---|---|
| Fragmentation and best fit | Memory allocation | The waste score's *stranded time*, and best-fit practitioner assignment |
| Protection levels and EMSR | Airline revenue management | The waste score's *lost capacity* for longer services |
| Assortment optimisation | Choice-based revenue management | The strictness slider: the business chooses how restricted the set of offered times is |
| Rules that relax near the date | Hotel minimum-stay rules | The relaxation window |
| Littlewood's rule and bid prices | Revenue management | Learning from history: wasted time is weighted by the chance it would have been booked |
| Testing policies by simulation | Scheduling research | The simulator, which compares strictness settings on generated demand |
| Recapture | Choice modelling | Logging searches that found nothing suitable, to estimate what hiding a time really costs |

The details are in [planning/00-overview.md](planning/00-overview.md), section 5.

## 7. Glossary

| Term | Meaning |
|---|---|
| Assortment optimisation | Choosing which set of options to offer, given how customers choose between them. |
| Bid price | The value a unit of capacity is expected to earn if kept free. Requests worth less than the total bid price of the units they use are refused. |
| Booking limit / protection level | How many units can be sold to lower-value requests / how many are held back for higher-value ones. |
| Competitive ratio | How far an online algorithm's result can be, in the worst case, from the best result possible with hindsight. |
| EMSR | Expected marginal seat revenue: shortcut methods that extend Littlewood's rule to many fare classes. |
| External fragmentation | Free capacity split into pieces too small to use. |
| Littlewood's rule | Accept a lower-value request unless the chance of a higher-value one using the same capacity is greater than the ratio of their values. |
| Markov decision process | A model of a series of decisions under uncertainty. It can find the best policy in principle, but is often too large to solve exactly. |
| NP-hard | A class of problems with no known method that finds the exact best answer quickly in every case. The work needed grows explosively as the problem gets bigger. |
| Online algorithm | An algorithm that makes each decision before seeing future requests. |
| Orphan night | A short gap between hotel or holiday-let stays that's hard to sell. |
| Recapture | A customer who doesn't get their first choice taking another offered option instead of leaving. |
| Revenue management | Selling perishable capacity (seats, rooms, time) to make the most of it under uncertain demand. |

## 8. References

**Revenue management**
- Littlewood, K. (1972). Forecasting and control of passenger bookings. *AGIFORS Symposium Proceedings* 12. Reprinted in *Journal of Revenue and Pricing Management* 4 (2005), 111–123. Summary: [Littlewood's rule (Wikipedia)](https://en.wikipedia.org/wiki/Littlewood%27s_rule)
- Belobaba, P. (1989). Application of a probabilistic decision model to airline seat inventory control. *Operations Research* 37(2), 183–197. [IDEAS](https://ideas.repec.org/a/inm/oropre/v37y1989i2p183-197.html). Summary: [EMSR (Wikipedia)](https://en.wikipedia.org/wiki/Expected_marginal_seat_revenue)
- Talluri, K. & van Ryzin, G. (1998). An analysis of bid-price controls for network revenue management. *Management Science* 44(11), 1577–1593. [IDEAS](https://ideas.repec.org/a/inm/ormnsc/v44y1998i11-part-1p1577-1593.html)
- Talluri, K. & van Ryzin, G. (2004). Revenue management under a general discrete choice model of consumer behavior. *Management Science* 50(1), 15–33. [Working paper](https://econ-papers.upf.edu/papers/533.pdf)
- Talluri, K. & van Ryzin, G. (2004). *The Theory and Practice of Revenue Management*. Kluwer.

**Healthcare appointment scheduling**
- Bailey, N. T. J. (1952). A study of queues and appointment systems in hospital out-patient departments, with special reference to waiting-times. *Journal of the Royal Statistical Society, Series B* 14(2), 185–199.
- Welch, J. D. & Bailey, N. T. J. (1952). Appointment systems in hospital outpatient departments. *The Lancet* 259, 1105–1108.
- Cayirli, T. & Veral, E. (2003). Outpatient scheduling in health care: a review of literature. *Production and Operations Management* 12(4), 519–549.
- Gupta, D. & Denton, B. (2008). Appointment scheduling in health care: challenges and opportunities. *IIE Transactions* 40(9), 800–819.
- Kaandorp, G. & Koole, G. (2007). Optimal outpatient appointment scheduling. *Health Care Management Science* 10, 217–229. [Springer](https://link.springer.com/article/10.1007/s10729-007-9015-x)
- Patrick, J., Puterman, M. L. & Queyranne, M. (2008). Dynamic multipriority patient scheduling for a diagnostic resource. *Operations Research* 56(6), 1507–1525. [IDEAS](https://ideas.repec.org/a/inm/oropre/v56y2008i6p1507-1525.html)

**Algorithms**
- Kleinberg, J. & Tardos, É. (2005). *Algorithm Design*, chapter 6 (weighted interval scheduling). Addison-Wesley.
- Lipton, R. J. & Tomkins, A. (1994). Online interval scheduling. *Proceedings of the 5th ACM-SIAM Symposium on Discrete Algorithms (SODA)*, 302–311. Later bounds: [Bachmann, Halldórsson & Shachnai](https://csaws.cs.technion.ac.il/~hadas/PUB/BHS.pdf)
- Spieksma, F. (1999). On the approximability of an interval scheduling problem. *Journal of Scheduling* 2, 215–227.
- Chuzhoy, J., Ostrovsky, R. & Rabani, Y. (2006). Approximation algorithms for the job interval selection problem and related scheduling problems. *Mathematics of Operations Research* 31(4), 730–738. [INFORMS](https://pubsonline.informs.org/doi/10.1287/moor.1060.0218)
- Wilson, P. R., Johnstone, M. S., Neely, M. & Boles, D. (1995). Dynamic storage allocation: a survey and critical review. *International Workshop on Memory Management*. [PDF](https://www.cs.hmc.edu/~oneill/gc-library/Wilson-Alloc-Survey-1995.pdf)

**Industry practice**
- PriceLabs: [What are orphan gaps, and how to use PriceLabs to leverage them?](https://hello.pricelabs.co/how-to-use-orphan-gaps-for-increasing-revenue/)
- Hostaway: [Orphan days (glossary)](https://www.hostaway.com/glossary/orphan-days/)
- Fresha: [How to reduce calendar gaps](https://www.fresha.com/en-GB/blog/how-to-reduce-calendar-gaps); [occupancy announcement (BarberEVO)](https://www.barberevo.com/fresha-users-boost-occupancy-by-up-to-44-with-new-feature)
- Timely: [Minimise gaps help article](https://help.gettimely.com/hc/en-gb/articles/360062490973)
- Ovatu: [Minimising gaps for online bookings](https://help.ovatu.com/online-booking/minimising-gaps-for-online-bookings)
- MassageBook: [Minimising gaps in your day](https://massagebook.com/marketing/how-massage-therapists-can-maximize-revenue-by-minimizing-gaps-in-their-days)
- Calendly community: [Please implement minimize gaps](https://community.calendly.com/how-do-i-40/please-implement-minimize-gaps-3535)
