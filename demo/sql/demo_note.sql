-- The intranet notes of the /under-the-hood/security page: two companies (1 = Acme, 2 = Globex), twelve notes each.
-- Titles and bodies are ordinary; the confidential column holds what only HR may search. The words `salary` and `lawsuit`
-- occur ONLY there, `budget` and `menu` occur in both companies' notes, and `bonus` is in a title and in the confidential
-- text of the same note. Seeded once by docker/init.sh (only while the table does not exist).

CREATE TABLE demo_note (
    id           bigint PRIMARY KEY,
    company_id   integer NOT NULL,
    title        text NOT NULL,
    body         text NOT NULL,
    confidential text
);

INSERT INTO demo_note (id, company_id, title, body, confidential) VALUES
-- Acme
(1, 1, 'Budget review Q3', 'We go through the budget of the third quarter and agree the hiring plan for the platform team.', 'Salary bands for the platform team rise by 6 percent from January; do not share before the announcement.'),
(2, 1, 'Project Atlas kickoff', 'Notes from the kickoff: scope, milestones and who owns the integration with the warehouse system.', NULL),
(3, 1, 'Cafeteria menu this week', 'Monday soup and bread, Tuesday pasta, Wednesday curry, Thursday fish, Friday pizza. A vegetarian option every day.', NULL),
(4, 1, 'Bonus plan 2027', 'How the yearly bonus works: targets are set in January and checked in June.', 'The bonus pool is 120 000 EUR and the sales team gets 40 percent of it. Salary adjustments are decided separately.'),
(5, 1, 'Office move to the new building', 'The move is planned for March. Boxes arrive on Monday; label them with your team name and desk number.', NULL),
(6, 1, 'Security awareness training', 'Every employee completes the short course on phishing and strong passwords before the end of the month.', NULL),
(7, 1, 'Vendor contract renewal', 'The cleaning contract and the coffee machine lease both end in December. The budget for next year is in the finance folder.', 'Legal advises letting the cleaning contract lapse: the vendor has an open lawsuit against another customer.'),
(8, 1, 'Team offsite planning', 'Two days in the mountains in May. Vote for the activities and tell us about dietary needs.', NULL),
(9, 1, 'Performance reviews', 'The review cycle starts in October. Book a conversation with your manager and prepare a short summary of your year.', 'Ratings are confidential: two engineers are on an improvement plan and their salary freeze is not public.'),
(10, 1, 'Customer feedback summary', 'Customers like the new dashboard, but the export is slow. The budget for a faster export is approved.', NULL),
(11, 1, 'Parking and bike room', 'The bike room is open from 6 to 22. Parking permits are renewed every year at the reception.', NULL),
(12, 1, 'Holiday calendar', 'Public holidays and the two company days off. Remember to book your holidays before the end of the quarter.', 'The holiday pay lawsuit with the former contractors is settled; the payments follow in spring.'),
-- Globex
(13, 2, 'Budget planning 2027', 'The finance team collects budget requests from every department until the end of next week.', 'A salary freeze for the logistics department is proposed; the board decides on Friday.'),
(14, 2, 'Warehouse safety rules', 'Helmets in zone B, forklift licences checked at the gate, and the new emergency exits are marked in green.', NULL),
(15, 2, 'Cafeteria menu and allergens', 'This week''s menu with allergen labels. The salad bar is now open until three in the afternoon.', NULL),
(16, 2, 'Supplier meeting notes', 'We agreed a delivery window of two days and a quarterly review of prices.', 'The supplier wants 4 percent more; legal says the lawsuit risk is low if we switch.'),
(17, 2, 'Bonus for the night shift', 'The night shift gets an extra allowance from next month. Details are in the handbook.', 'The bonus costs 18 000 EUR per quarter; the salary scale for night work is attached.'),
(18, 2, 'New truck fleet', 'Six electric trucks arrive in spring. Drivers book a training slot with the transport desk.', NULL),
(19, 2, 'Intranet migration', 'The intranet moves to a new platform on the first weekend of the month. Expect a short outage on Saturday.', NULL),
(20, 2, 'Quarterly budget forecast', 'The forecast for the next quarter shows steady growth in the north region, and the budget holds.', NULL),
(21, 2, 'Compliance training', 'A short course about export rules, mandatory for everyone who works in shipping.', 'An audit found three missing certificates; a lawsuit is possible if they are not fixed by June.'),
(22, 2, 'Team lunch on Friday', 'We order from the Thai place. Add your choice to the sheet before Wednesday.', NULL),
(23, 2, 'Recycling and waste', 'Paper, plastic and glass have separate bins on every floor. Please keep the cardboard flat.', NULL),
(24, 2, 'Annual review schedule', 'Managers book review conversations in November. Prepare the goals for next year.', 'Two managers get a salary raise of 8 percent after the review.');
